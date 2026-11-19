<?php

namespace justinholtweb\yarn\models;

use Craft;
use craft\db\Query;
use craft\db\Table;

/**
 * Everything an edge source needs, built once and handed to all of them.
 *
 * The expensive lookups here — the nested-ownership map, the field names, the URI index — are
 * each wanted by more than one source. Building them per source would read `elements_owners`
 * three times on a site where it is the largest table in the database.
 */
class BuildContext
{
    /** @var array<int, int> Nested element id => its immediate owner. */
    private array $ownerOf = [];

    /** @var array<int, int> Nested element id => the field it sits in on that owner. */
    private array $fieldOf = [];

    /** @var array<int, array{0: int, 1: ?int}> Memoized: element id => [top owner, outermost field]. */
    private array $resolved = [];

    /** @var array<int, string>|null Field id => name. */
    private ?array $fieldNames = null;

    /** @var int[]|null Field ids the settings say to ignore. */
    private ?array $ignoredFieldIds = null;

    /**
     * @param bool $wholeSite Load the whole of `elements_owners` up front. Right for a graph
     * build; ruinous for the sidebar panel, which passes false and calls
     * {@see self::loadOwnershipFor()} with only the ids it is about to roll up.
     */
    public function __construct(
        public int $siteId,
        public Settings $settings,
        bool $wholeSite = true,
    ) {
        if ($settings->rollUpNested && $wholeSite) {
            $this->loadOwnership();
        }
    }

    /**
     * Ownership for a handful of ids, walked up a level at a time.
     *
     * Owners that are drafts or revisions are skipped: in Craft 5 a draft shares its canonical's
     * nested entries through `elements_owners`, and rolling a block up to the draft would report
     * the page as used by a draft nobody can see in the index.
     *
     * @param int[] $ids
     */
    public function loadOwnershipFor(array $ids): void
    {
        if (!$this->settings->rollUpNested) {
            return;
        }

        $pending = array_values(array_diff(array_unique(array_map('intval', $ids)), array_keys($this->ownerOf)));

        for ($round = 0; $pending !== [] && $round < 10; $round++) {
            $owners = (new Query())
                ->select(['eo.elementId', 'eo.ownerId'])
                ->from(['eo' => Table::ELEMENTS_OWNERS])
                ->innerJoin(['oe' => Table::ELEMENTS], '[[oe.id]] = [[eo.ownerId]]')
                ->where(['eo.elementId' => $pending, 'oe.draftId' => null, 'oe.revisionId' => null])
                ->pairs();

            if ($owners === []) {
                break;
            }

            foreach ($owners as $elementId => $ownerId) {
                $this->ownerOf[(int)$elementId] = (int)$ownerId;
            }

            $fields = (new Query())
                ->select(['id', 'fieldId'])
                ->from([Table::ENTRIES])
                ->where(['id' => array_keys($owners)])
                ->andWhere(['not', ['fieldId' => null]])
                ->pairs();

            foreach ($fields as $id => $fieldId) {
                $this->fieldOf[(int)$id] = (int)$fieldId;
            }

            $pending = array_values(array_diff(
                array_unique(array_map('intval', array_values($owners))),
                array_keys($this->ownerOf),
            ));
        }

        // Anything resolved before this call may now resolve further.
        $this->resolved = [];
    }

    /**
     * Rewrites an element id to the element a reader would open.
     *
     * With roll-up on, a relation held by Matrix entry #9021 is reported as held by the page that
     * owns it. Without this, a site built on Matrix produces a graph in which almost no node is
     * anything a person has ever seen in the control panel.
     *
     * @return array{0: int, 1: ?int} The resolved id, and the field on the owner it came through.
     */
    public function rollUp(int $id): array
    {
        if (!$this->settings->rollUpNested) {
            return [$id, null];
        }

        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }

        $current = $id;
        $field = null;
        $seen = [$id => true];

        // Iterative, and guarded: `elements_owners` has no constraint preventing a cycle, and one
        // bad row would otherwise hang the build rather than skew it.
        while (isset($this->ownerOf[$current])) {
            $field = $this->fieldOf[$current] ?? $field;
            $current = $this->ownerOf[$current];

            if (isset($seen[$current])) {
                break;
            }

            $seen[$current] = true;
        }

        return $this->resolved[$id] = [$current, $field];
    }

    public function isNested(int $id): bool
    {
        return isset($this->ownerOf[$id]);
    }

    public function fieldName(?int $fieldId): string
    {
        if ($fieldId === null) {
            return '';
        }

        if ($this->fieldNames === null) {
            $this->fieldNames = [];

            foreach (Craft::$app->getFields()->getAllFields() as $field) {
                $this->fieldNames[$field->id] = $field->name;
            }
        }

        return $this->fieldNames[$fieldId] ?? Craft::t('yarn', 'Field #{id}', ['id' => $fieldId]);
    }

    /** @return int[] */
    public function ignoredFieldIds(): array
    {
        if ($this->ignoredFieldIds !== null) {
            return $this->ignoredFieldIds;
        }

        $this->ignoredFieldIds = [];

        foreach ($this->settings->ignoredFields as $handle) {
            $field = Craft::$app->getFields()->getFieldByHandle($handle);

            if ($field?->id !== null) {
                $this->ignoredFieldIds[] = $field->id;
            }
        }

        return $this->ignoredFieldIds;
    }

    /**
     * Element ids that may appear in the graph at all: no drafts, no revisions, nothing archived.
     *
     * Applied as a subquery rather than a fetched list on purpose. `relations` on a site with
     * revisions turned on is overwhelmingly revision rows — the largest site I have measured had
     * 1.4 million of them against 31,000 real ones — and pulling the allowed ids into PHP to
     * filter there is the difference between a query and an out-of-memory.
     */
    public static function liveElementIds(): Query
    {
        return (new Query())
            ->select(['id'])
            ->from([Table::ELEMENTS])
            ->where([
                'draftId' => null,
                'revisionId' => null,
                'archived' => false,
            ]);
    }

    /**
     * Reads `elements_owners` — and, for nested entries, the field they sit in.
     */
    private function loadOwnership(): void
    {
        $rows = (new Query())
            ->select(['elementId', 'ownerId'])
            ->from([Table::ELEMENTS_OWNERS])
            ->all();

        foreach ($rows as $row) {
            $this->ownerOf[(int)$row['elementId']] = (int)$row['ownerId'];
        }

        if ($this->ownerOf === []) {
            return;
        }

        // Craft's own nested entries carry the field on the entries table. Other element types
        // with owners (a plugin's own nested element) simply get no field name, which reads as
        // "nested" rather than as a wrong one.
        $fields = (new Query())
            ->select(['id', 'fieldId'])
            ->from([Table::ENTRIES])
            ->where(['not', ['fieldId' => null]])
            ->all();

        foreach ($fields as $row) {
            $this->fieldOf[(int)$row['id']] = (int)$row['fieldId'];
        }
    }
}
