<?php

namespace justinholtweb\yarn\sources;

use Craft;
use craft\db\Query;
use craft\db\Table;
use justinholtweb\yarn\models\Edge;

/**
 * Ownership of nested elements: a page to the Matrix entries inside it.
 *
 * Only runs when roll-up is switched off. With roll-up on, nested elements are not nodes at all —
 * their relations were reattributed to whatever owns them — so an ownership edge would point at
 * something that is not in the graph.
 */
class NestedSource extends BaseEdgeSource
{
    public static function id(): string
    {
        return 'nested';
    }

    public function displayName(): string
    {
        return Craft::t('yarn', 'Nested elements');
    }

    public function isEnabled(): bool
    {
        return !$this->context->settings->rollUpNested;
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function collect(): iterable
    {
        $rows = (new Query())
            ->select(['o.ownerId', 'o.elementId', 'n.fieldId'])
            ->from(['o' => Table::ELEMENTS_OWNERS])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[o.elementId]]')
            ->leftJoin(['n' => Table::ENTRIES], '[[n.id]] = [[o.elementId]]')
            ->where([
                'e.draftId' => null,
                'e.revisionId' => null,
                'e.archived' => false,
                'e.dateDeleted' => null,
            ]);

        foreach ($rows->each(1000) as $row) {
            yield new Edge(
                from: (int)$row['ownerId'],
                to: (int)$row['elementId'],
                kind: Edge::KIND_NESTED,
                label: $this->context->fieldName($row['fieldId'] !== null ? (int)$row['fieldId'] : null)
                    ?: Craft::t('yarn', 'Nested'),
                fieldId: $row['fieldId'] !== null ? (int)$row['fieldId'] : null,
            );
        }
    }
}
