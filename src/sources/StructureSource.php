<?php

namespace justinholtweb\yarn\sources;

use Craft;
use craft\db\Query;
use craft\db\Table;
use justinholtweb\yarn\models\Edge;

/**
 * Structure hierarchy: a parent entry or category to each of its children.
 *
 * Off by default, because a hierarchy is not a dependency — deleting a parent does not break a
 * child, it re-parents it. It is worth switching on for the map, where a structure section that
 * appears as fifty unconnected dots is much easier to read as a tree.
 */
class StructureSource extends BaseEdgeSource
{
    public static function id(): string
    {
        return 'structure';
    }

    public function displayName(): string
    {
        return Craft::t('yarn', 'Structure hierarchy');
    }

    public function collect(): iterable
    {
        // One query for the lot, then parents worked out with a stack.
        //
        // The obvious alternative — asking for each element's parent by the nested-set bounds —
        // is a query per element, and a structure section is exactly where there are thousands of
        // them. `entries.parentId` would be a third option, but it is only populated from Craft
        // 5.3 onwards and on entries saved since; the nested set is authoritative in every case.
        $rows = (new Query())
            ->select(['s.structureId', 's.root', 's.lft', 's.level', 's.elementId'])
            ->from(['s' => Table::STRUCTUREELEMENTS])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[s.elementId]]')
            ->where([
                'e.draftId' => null,
                'e.revisionId' => null,
                'e.archived' => false,
                'e.dateDeleted' => null,
            ])
            ->andWhere(['not', ['s.elementId' => null]])
            ->orderBy(['s.structureId' => SORT_ASC, 's.root' => SORT_ASC, 's.lft' => SORT_ASC])
            ->all();

        /** @var array<int, int> $ancestors level => element id */
        $ancestors = [];
        $branch = null;

        foreach ($rows as $row) {
            $key = $row['structureId'] . ':' . $row['root'];

            if ($key !== $branch) {
                $ancestors = [];
                $branch = $key;
            }

            $level = (int)$row['level'];
            $id = (int)$row['elementId'];
            $ancestors[$level] = $id;

            // Anything deeper than this belongs to a branch we have walked past.
            foreach (array_keys($ancestors) as $known) {
                if ($known > $level) {
                    unset($ancestors[$known]);
                }
            }

            if (isset($ancestors[$level - 1])) {
                yield new Edge(
                    from: $ancestors[$level - 1],
                    to: $id,
                    kind: Edge::KIND_STRUCTURE,
                    label: Craft::t('yarn', 'Child'),
                );
            }
        }
    }
}
