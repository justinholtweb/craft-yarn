<?php

namespace justinholtweb\yarn\sources;

use Craft;
use craft\db\Query;
use craft\db\Table;
use justinholtweb\yarn\models\Edge;

/**
 * Craft's own `relations` table: Entries, Assets, Categories, Tags and Users fields.
 *
 * Always on. Everything else Yarn reads is a supplement to this.
 */
class RelationsSource extends BaseEdgeSource
{
    public static function id(): string
    {
        return 'relations';
    }

    public function displayName(): string
    {
        return Craft::t('yarn', 'Relation fields');
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function collect(): iterable
    {
        $ignored = $this->context->ignoredFieldIds();

        $query = (new Query())
            ->select(['r.sourceId', 'r.targetId', 'r.fieldId'])
            ->from(['r' => Table::RELATIONS])
            // Both ends have to be real content. A revision's relations are a snapshot of what an
            // entry *used* to point at, and counting them means every asset ever swapped out still
            // reads as "in use" — the single most misleading thing a relations report can say,
            // because it is the one that stops you cleaning anything up.
            //
            // Joins rather than `IN (subquery)`: `relations` on a versioned site is mostly
            // revision rows, and the join runs off the primary key both times.
            ->innerJoin(['se' => Table::ELEMENTS], '[[se.id]] = [[r.sourceId]]')
            ->innerJoin(['te' => Table::ELEMENTS], '[[te.id]] = [[r.targetId]]')
            ->where([
                'or',
                ['r.sourceSiteId' => null],
                ['r.sourceSiteId' => $this->context->siteId],
            ])
            ->andWhere([
                'se.draftId' => null,
                'se.revisionId' => null,
                'se.archived' => false,
                'te.draftId' => null,
                'te.revisionId' => null,
                'te.archived' => false,
            ])
            // Soft-deleted targets are kept deliberately: a relation pointing into the trash is
            // exactly what the broken-target check exists to find.
            ->andWhere(['se.dateDeleted' => null])
            ->orderBy(['r.sourceId' => SORT_ASC]);

        if ($ignored !== []) {
            $query->andWhere(['not', ['r.fieldId' => $ignored]]);
        }

        foreach ($query->each(1000) as $row) {
            $sourceId = (int)$row['sourceId'];
            $targetId = (int)$row['targetId'];
            $fieldId = $row['fieldId'] !== null ? (int)$row['fieldId'] : null;

            [$from, $ownerField] = $this->context->rollUp($sourceId);

            yield new Edge(
                from: $from,
                to: $targetId,
                kind: Edge::KIND_FIELD,
                label: $this->context->fieldName($fieldId),
                fieldId: $fieldId,
                via: $from !== $sourceId ? $this->context->fieldName($ownerField) : null,
                viaId: $from !== $sourceId ? $sourceId : null,
            );
        }
    }
}
