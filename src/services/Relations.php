<?php

namespace justinholtweb\yarn\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use justinholtweb\yarn\models\BuildContext;
use justinholtweb\yarn\models\Edge;
use justinholtweb\yarn\models\Graph as GraphModel;
use justinholtweb\yarn\models\Node;
use justinholtweb\yarn\Plugin;

/**
 * Relations for one element, from both directions.
 *
 * Two paths in here, deliberately. {@see self::view()} reads the assembled graph and knows about
 * everything — reference tags, hard-coded links, nested blocks. {@see self::directUsages()} goes
 * straight to the relations table for one element.
 *
 * The second exists because the sidebar panel renders on every element edit screen, and building
 * a whole-site graph to answer "is this in use" would put seconds on the load of a page nobody
 * opened Yarn to look at.
 */
class Relations extends Component
{
    /** @var int[]|null Memoized for the life of the request. */
    private ?array $ignoredFieldIds = null;

    /** The settings fingerprint the memo above was built under. */
    private ?string $ignoredFor = null;

    /**
     * The full picture for one element: what it points at, what points at it, grouped for reading.
     *
     * @return array{
     *     node: Node|null,
     *     outgoing: array<string, array{label: string, kind: string, kindLabel: string, edges: array<int, array{edge: Edge, node: Node}>}>,
     *     incoming: array<string, array{label: string, kind: string, kindLabel: string, edges: array<int, array{edge: Edge, node: Node}>}>,
     *     outCount: int,
     *     inCount: int,
     * }
     */
    public function view(GraphModel $graph, int $elementId): array
    {
        $node = $graph->node($elementId);

        return [
            'node' => $node,
            'outgoing' => $this->group($graph, $graph->outgoing($elementId), 'to'),
            'incoming' => $this->group($graph, $graph->incoming($elementId), 'from'),
            'outCount' => count($graph->outgoing($elementId)),
            'inCount' => count($graph->incoming($elementId)),
        ];
    }

    /**
     * Groups edges by the field they came through, so a page with thirty relations reads as four
     * fields rather than as thirty lines.
     *
     * @param Edge[] $edges
     * @param 'to'|'from' $end Which end of the edge is the *other* element.
     * @return array<string, array{label: string, kind: string, kindLabel: string, edges: array<int, array{edge: Edge, node: Node}>}>
     */
    private function group(GraphModel $graph, array $edges, string $end): array
    {
        $groups = [];

        foreach ($edges as $edge) {
            $other = $graph->node($end === 'to' ? $edge->to : $edge->from);

            if ($other === null) {
                continue;
            }

            $key = $edge->kind . ':' . ($edge->fieldId ?? $edge->label);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'label' => $edge->label !== '' ? $edge->label : $edge->kindLabel(),
                    'kind' => $edge->kind,
                    'kindLabel' => $edge->kindLabel(),
                    'edges' => [],
                ];
            }

            $groups[$key]['edges'][] = ['edge' => $edge, 'node' => $other];
        }

        uasort($groups, fn(array $a, array $b) => [$a['kind'], $a['label']] <=> [$b['kind'], $b['label']]);

        return $groups;
    }

    /**
     * What would be left pointing at nothing if this element went away.
     *
     * @return array{direct: Node[], indirect: array<int, array{node: Node, hops: int}>}
     */
    public function impact(GraphModel $graph, int $elementId, int $depth = 3): array
    {
        $direct = [];

        foreach ($graph->incoming($elementId) as $edge) {
            $node = $graph->node($edge->from);

            if ($node !== null) {
                $direct[$node->id] = $node;
            }
        }

        $indirect = [];

        foreach ($graph->dependents($elementId, $depth) as $id => $hops) {
            if ($hops < 2) {
                continue;
            }

            $node = $graph->node($id);

            if ($node !== null) {
                $indirect[] = ['node' => $node, 'hops' => $hops];
            }
        }

        usort($indirect, fn(array $a, array $b) => [$a['hops'], $a['node']->label] <=> [$b['hops'], $b['node']->label]);

        return ['direct' => array_values($direct), 'indirect' => $indirect];
    }

    // -------------------------------------------------------------------- the cheap single-shot

    /**
     * Elements that point at this one, straight from the relations table.
     *
     * Rolled up the same way the graph rolls up, so an asset used inside a Matrix block is
     * reported as used by the page — which is what the person staring at the delete button needs
     * to know.
     *
     * @return array<int, array{id: int, fieldId: int|null, viaId: int|null}>
     */
    public function directUsages(int $elementId, ?int $siteId = null): array
    {
        return $this->directRelations($elementId, $siteId, 'targetId', 'sourceId');
    }

    /**
     * @return array<int, array{id: int, fieldId: int|null, viaId: int|null}>
     */
    public function directUses(int $elementId, ?int $siteId = null): array
    {
        return $this->directRelations($elementId, $siteId, 'sourceId', 'targetId');
    }

    public function countUsages(int $elementId, ?int $siteId = null): int
    {
        return count($this->directUsages($elementId, $siteId));
    }

    /**
     * @return array<int, array{id: int, fieldId: int|null, viaId: int|null}>
     */
    private function directRelations(int $elementId, ?int $siteId, string $anchor, string $other): array
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $settings = Plugin::getInstance()->getSettings();

        $rows = (new Query())
            ->select(["r.$other", 'r.fieldId'])
            ->from(['r' => Table::RELATIONS])
            ->innerJoin(['oe' => Table::ELEMENTS], "[[oe.id]] = [[r.$other]]")
            ->where(["r.$anchor" => $elementId])
            ->andWhere([
                'or',
                ['r.sourceSiteId' => null],
                ['r.sourceSiteId' => $siteId],
            ])
            ->andWhere([
                'oe.draftId' => null,
                'oe.revisionId' => null,
                'oe.archived' => false,
                'oe.dateDeleted' => null,
            ])
            ->all();

        if ($rows === []) {
            return [];
        }

        $ignored = array_flip($this->ignoredFieldIds());

        // Only the *source* end is ever nested — a relation targets whatever it targets. So roll
        // up when we are looking at sources, and leave targets alone.
        $rollUp = $anchor === 'targetId' && $settings->rollUpNested;
        $owners = $rollUp
            ? $this->ownersOf(array_map(fn(array $row) => (int)$row[$other], $rows))
            : [];

        $found = [];

        foreach ($rows as $row) {
            $fieldId = $row['fieldId'] !== null ? (int)$row['fieldId'] : null;

            if ($fieldId !== null && isset($ignored[$fieldId])) {
                continue;
            }

            $raw = (int)$row[$other];
            $id = $owners[$raw] ?? $raw;

            $found[$id . ':' . $fieldId] = [
                'id' => $id,
                'fieldId' => $fieldId,
                'viaId' => $id !== $raw ? $raw : null,
            ];
        }

        return array_values($found);
    }

    /**
     * Resolves a handful of element ids to their top-level owners.
     *
     * Deliberately not {@see BuildContext}, which loads the whole of `elements_owners` — right for
     * one whole-site build, ruinous for a sidebar panel on a site where that table has a million
     * rows. This walks up a level at a time, querying only the ids still in play, and on a normal
     * site stops after one or two rounds.
     *
     * @param int[] $ids
     * @return array<int, int> Only the ids that turned out to be nested.
     */
    private function ownersOf(array $ids): array
    {
        $resolved = [];
        /** @var array<int, int> $pending original id => id currently being resolved */
        $pending = array_combine($ids, $ids);
        $rounds = 0;

        while ($pending !== [] && $rounds < 10) {
            $rounds++;

            $owners = (new Query())
                ->select(['elementId', 'ownerId'])
                ->from([Table::ELEMENTS_OWNERS])
                ->where(['elementId' => array_values(array_unique($pending))])
                ->pairs();

            if ($owners === []) {
                break;
            }

            $next = [];

            foreach ($pending as $original => $current) {
                if (!isset($owners[$current])) {
                    continue;
                }

                $owner = (int)$owners[$current];
                $resolved[$original] = $owner;
                $next[$original] = $owner;
            }

            $pending = $next;
        }

        return $resolved;
    }

    /** @return int[] */
    private function ignoredFieldIds(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        // Keyed on the fingerprint rather than memoized outright: settings can change inside one
        // run — the settings screen saving, a test reconfiguring — and a memo that outlives the
        // change goes on filtering by the old list while every other part of Yarn uses the new one.
        $fingerprint = $settings->graphFingerprint();

        if ($this->ignoredFieldIds !== null && $this->ignoredFor === $fingerprint) {
            return $this->ignoredFieldIds;
        }

        $this->ignoredFor = $fingerprint;
        $this->ignoredFieldIds = [];

        foreach ($settings->ignoredFields as $handle) {
            $field = Craft::$app->getFields()->getFieldByHandle($handle);

            if ($field?->id !== null) {
                $this->ignoredFieldIds[] = $field->id;
            }
        }

        return $this->ignoredFieldIds;
    }

    /**
     * Assets grouped by volume, with how often each is used. The delete-with-confidence screen.
     *
     * @return array<int, array{node: Node, uses: int, usedBy: Node[]}>
     */
    public function assetUsage(GraphModel $graph, ?string $groupKey = null, bool $unusedOnly = false): array
    {
        $rows = [];

        foreach ($graph->nodes as $node) {
            if ($node->kind !== Node::KIND_ASSET) {
                continue;
            }

            if ($groupKey !== null && $groupKey !== '' && $node->groupKey !== $groupKey) {
                continue;
            }

            if ($unusedOnly && $node->inCount > 0) {
                continue;
            }

            $usedBy = [];

            foreach ($graph->incoming($node->id) as $edge) {
                $other = $graph->node($edge->from);

                if ($other !== null) {
                    $usedBy[$other->id] = $other;
                }
            }

            $rows[] = ['node' => $node, 'uses' => $node->inCount, 'usedBy' => array_values($usedBy)];
        }

        usort($rows, fn(array $a, array $b) => [$a['uses'], $a['node']->label] <=> [$b['uses'], $b['node']->label]);

        return $rows;
    }

    /**
     * Every global set and what it reaches — one hop, and then everything below.
     *
     * Globals are the blind spot. Nothing in the control panel tells you that the image you are
     * about to delete is the one in the footer of every page on the site.
     *
     * @return array<int, array{node: Node, direct: Node[], reach: int}>
     */
    public function globalUsage(GraphModel $graph, int $depth = 2): array
    {
        $rows = [];

        foreach ($graph->nodes as $node) {
            if ($node->kind !== Node::KIND_GLOBAL) {
                continue;
            }

            $direct = [];

            foreach ($graph->outgoing($node->id) as $edge) {
                $other = $graph->node($edge->to);

                if ($other !== null) {
                    $direct[$other->id] = $other;
                }
            }

            $reach = $graph->ego($node->id, $depth, GraphModel::DIRECTION_OUT);
            unset($reach[$node->id]);

            $rows[] = ['node' => $node, 'direct' => array_values($direct), 'reach' => count($reach)];
        }

        usort($rows, fn(array $a, array $b) => $a['node']->label <=> $b['node']->label);

        return $rows;
    }
}
