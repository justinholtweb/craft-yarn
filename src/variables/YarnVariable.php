<?php

namespace justinholtweb\yarn\variables;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use justinholtweb\yarn\models\Finding;
use justinholtweb\yarn\models\Graph as GraphModel;
use justinholtweb\yarn\Plugin;

/**
 * `craft.yarn` — relations from a template.
 *
 * The two that earn their keep on a front end are {@see self::usedBy()} and {@see self::isUsed()}:
 * "other pages that mention this one" is a footer block most sites build by hand and maintain by
 * accident.
 *
 * Everything that needs the whole graph is here too, but think before putting it in a template —
 * a whole-site graph is a report, not a page element.
 */
class YarnVariable
{
    /**
     * Elements this one points at.
     *
     * @return ElementInterface[]
     */
    public function uses(ElementInterface|int $element, ?int $siteId = null): array
    {
        [$id, $siteId] = $this->resolve($element, $siteId);

        return $this->elements(Plugin::getInstance()->relations->directUses($id, $siteId), $siteId);
    }

    /**
     * Elements that point at this one.
     *
     * @return ElementInterface[]
     */
    public function usedBy(ElementInterface|int $element, ?int $siteId = null): array
    {
        [$id, $siteId] = $this->resolve($element, $siteId);

        return $this->elements(Plugin::getInstance()->relations->directUsages($id, $siteId), $siteId);
    }

    public function usageCount(ElementInterface|int $element, ?int $siteId = null): int
    {
        [$id, $siteId] = $this->resolve($element, $siteId);

        return Plugin::getInstance()->relations->countUsages($id, $siteId);
    }

    public function isUsed(ElementInterface|int $element, ?int $siteId = null): bool
    {
        return $this->usageCount($element, $siteId) > 0;
    }

    /**
     * The whole graph. Expensive on a first call, cached after.
     */
    public function graph(?int $siteId = null): GraphModel
    {
        return Plugin::getInstance()->graph->get($siteId);
    }

    /**
     * @param string[] $only
     * @return Finding[]
     */
    public function findings(?int $siteId = null, array $only = []): array
    {
        return Plugin::getInstance()->findings->run($this->graph($siteId), $only);
    }

    /**
     * The shortest chain of relations joining two elements, as a list of `{from, to, label}`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function path(ElementInterface|int $from, ElementInterface|int $to, ?int $siteId = null): array
    {
        [$fromId, $siteId] = $this->resolve($from, $siteId);
        [$toId] = $this->resolve($to, $siteId);

        $graph = $this->graph($siteId);

        return array_map(fn($edge) => $edge->toArray(), $graph->path($fromId, $toId));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolve(ElementInterface|int $element, ?int $siteId): array
    {
        if ($element instanceof ElementInterface) {
            return [(int)$element->id, $siteId ?? (int)$element->siteId];
        }

        return [$element, $siteId ?? Craft::$app->getSites()->getCurrentSite()->id];
    }

    /**
     * Turns relation rows into elements, in one query per element type rather than one per row.
     *
     * @param array<int, array{id: int, fieldId: int|null, viaId: int|null}> $rows
     * @return ElementInterface[]
     */
    private function elements(array $rows, int $siteId): array
    {
        $ids = array_values(array_unique(array_column($rows, 'id')));

        if ($ids === []) {
            return [];
        }

        $found = [];

        // `getElementById()` per row is a query per row. Grouping by type lets each type answer in
        // one — and a relations panel on a busy page is regularly forty rows.
        foreach ($this->typesFor($ids) as $type => $typeIds) {
            /** @var ElementQuery $query */
            $query = $type::find();
            $elements = $query
                ->id($typeIds)
                ->siteId($siteId)
                // Live only on the front end: a "pages that mention this one" footer must not list
                // a draft-in-all-but-name disabled entry. The control panel wants the lot.
                ->status(Craft::$app->getRequest()->getIsCpRequest() ? null : Element::STATUS_ENABLED)
                ->limit(null)
                ->all();

            foreach ($elements as $element) {
                $found[(int)$element->id] = $element;
            }
        }

        $ordered = [];

        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $ordered[] = $found[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param int[] $ids
     * @return array<class-string<ElementInterface>, int[]>
     */
    private function typesFor(array $ids): array
    {
        $rows = (new Query())
            ->select(['id', 'type'])
            ->from([Table::ELEMENTS])
            ->where(['id' => $ids])
            ->all();

        $byType = [];

        foreach ($rows as $row) {
            $type = (string)$row['type'];

            if (class_exists($type)) {
                $byType[$type][] = (int)$row['id'];
            }
        }

        return $byType;
    }
}
