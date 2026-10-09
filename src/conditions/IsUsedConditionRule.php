<?php

namespace justinholtweb\yarn\conditions;

use Craft;
use craft\base\conditions\BaseLightswitchConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\yarn\Plugin;

/**
 * "Is used" — for the Assets and Entries indexes, and anywhere else Craft takes an element
 * condition (custom sources, relation field conditions, element exports).
 *
 * The query side is one correlated `EXISTS` against `relations`, added to the element query's own
 * subquery: no graph build, no element loaded, and the database stops at the first matching row.
 * That is what keeps "unused images in this volume" fast on a 40,000-asset index.
 *
 * Relation fields only, like the sidebar panel and the "Used by" column. Reference tags and
 * hard-coded links live in field content, and finding them means reading all of it — which is
 * the graph's job, on the Assets screen in Yarn itself.
 */
class IsUsedConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('yarn', 'Is used');
    }

    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var ElementQuery $query */
        $query->andWhere([
            $this->value ? 'exists' : 'not exists',
            Plugin::getInstance()->relations->usedSubquery(),
        ]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        $used = $element->id !== null
            && Plugin::getInstance()->relations->countUsages($element->id, $element->siteId) > 0;

        return $this->matchValue($used);
    }
}
