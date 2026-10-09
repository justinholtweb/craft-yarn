<?php
/**
 * The "Used by" index column and the "Is used" condition rule.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-yarn/tests/integration/usage.php
 *
 * Builds three entries — one that relates to another, and one nothing touches — and proves the
 * column, the batch count and the rule all agree, against real element queries. Self-cleaning;
 * settings changes are in memory only.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\Db;
use justinholtweb\yarn\conditions\IsUsedConditionRule;
use justinholtweb\yarn\models\Settings;
use justinholtweb\yarn\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$original = $plugin->getSettings()->toArray();

function configure(array $overrides = []): void
{
    global $original;

    $settings = new Settings();
    $settings->setAttributes(array_merge($original, $overrides), false);
    Plugin::getInstance()->setSettings($settings->toArray());
}

/** @return int[] */
function idsMatching(bool $used, array $ids, string $type = Entry::class): array
{
    $query = $type::find()->id($ids)->status(null)->orderBy(['elements.id' => SORT_ASC]);
    $condition = $type::createCondition();
    $condition->addConditionRule(new IsUsedConditionRule(['value' => $used]));
    $condition->modifyQuery($query);

    return array_map('intval', $query->ids());
}

section('Registration');

check('assets and entries get a "Used by" column', function() {
    return (isset(Entry::tableAttributes()['yarnUsage']) && isset(Asset::tableAttributes()['yarnUsage']))
        ?: 'missing';
});

check('categories do not — the column is for assets and entries', function() {
    return !isset(Category::tableAttributes()['yarnUsage']) ?: 'leaked onto categories';
});

check('asset and entry conditions offer "Is used"; category conditions do not', function() {
    $has = fn(string $type) => in_array(
        IsUsedConditionRule::class,
        array_map(fn($rule) => $rule::class, $type::createCondition()->getSelectableConditionRules()),
        true,
    );

    return ($has(Entry::class) && $has(Asset::class) && !$has(Category::class)) ?: 'wrong registration';
});

$entrySection = null;

foreach (Craft::$app->getEntries()->getAllSections() as $candidate) {
    if ($candidate->type !== craft\models\Section::TYPE_SINGLE && $candidate->getEntryTypes()) {
        $entrySection = $candidate;
        break;
    }
}

$field = (new Query())->select(['id', 'handle'])->from([Table::FIELDS])->where(['type' => craft\fields\Entries::class])->one();

if ($entrySection === null || !$field) {
    echo "  ! skipped — no channel/structure section or no Entries field to borrow\n";
} else {
    $made = [];
    $make = function(string $title) use ($entrySection, &$made): Entry {
        $entry = new Entry();
        $entry->sectionId = $entrySection->id;
        $entry->typeId = $entrySection->getEntryTypes()[0]->id;
        $entry->title = $title;

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException(json_encode($entry->getErrors()));
        }

        return $made[] = $entry;
    };

    $source = $make('Yarn usage — source');
    $used = $make('Yarn usage — used');
    $unused = $make('Yarn usage — unused');
    $ids = [$source->id, $used->id, $unused->id];
    $relationRows = [];

    $relate = function(int $from, int $to) use ($field, &$relationRows) {
        Db::insert(Table::RELATIONS, [
            'fieldId' => (int)$field['id'],
            'sourceId' => $from,
            'sourceSiteId' => null,
            'targetId' => $to,
            'sortOrder' => 1,
        ]);
        $relationRows[] = [$from, $to];
    };

    $relate($source->id, $used->id);

    section('The column');

    check('the batch count agrees with the one-at-a-time count', function() use ($plugin, $ids) {
        $batch = $plugin->relations->usageCounts($ids);
        $single = array_combine($ids, array_map(fn($id) => $plugin->relations->countUsages($id), $ids));

        return $batch === $single ?: json_encode([$batch, $single]);
    });

    check('without permission to view Yarn, the count is shown but not linked', function() use ($ids, $used) {
        Craft::$app->getUser()->setIdentity(null);
        $html = Entry::find()->id($used->id)->status(null)->one()->getAttributeHtml('yarnUsage');

        return ($html === '<span>1</span>') ?: $html;
    });

    check('the cell shows 1 for the used entry, linked to Yarn, and a light 0 for the others', function() use ($ids, $used, $unused) {
        Craft::$app->getUser()->setIdentity(craft\elements\User::find()->admin()->status(null)->one());
        $entries = Entry::find()->id($ids)->status(null)->indexBy('id')->all();
        $usedHtml = $entries[$used->id]->getAttributeHtml('yarnUsage');
        $unusedHtml = $entries[$unused->id]->getAttributeHtml('yarnUsage');

        return (strip_tags($usedHtml) === '1' && str_contains($usedHtml, 'yarn/element/' . $used->id)
            && strip_tags($unusedHtml) === '0' && str_contains($unusedHtml, 'light'))
            ?: json_encode([$usedHtml, $unusedHtml]);
    });

    check('a page of cells costs one count, not one per row', function() use ($plugin, $ids) {
        $spy = new class() extends justinholtweb\yarn\services\Relations {
            public int $calls = 0;

            public function usageCounts(array $ids, ?int $siteId = null): array
            {
                $this->calls++;

                return parent::usageCounts($ids, $siteId);
            }
        };
        $real = $plugin->relations;
        $plugin->set('relations', $spy);

        // Rows the earlier check has not rendered, so nothing is memoized for them yet.
        $page = Entry::find()->id(array_merge(['not'], $ids))->status(null)->limit(20)->all();

        foreach ($page as $entry) {
            $entry->getAttributeHtml('yarnUsage');
        }

        $plugin->set('relations', $real);

        return (count($page) < 2 || $spy->calls === 1) ?: count($page) . " rows, {$spy->calls} counts";
    });

    section('The condition rule, applied to real queries');

    check('"Is used" on finds only the used entry', function() use ($ids, $used) {
        $found = idsMatching(true, $ids);

        return $found === [$used->id] ?: json_encode($found);
    });

    check('"Is used" off finds the other two', function() use ($ids, $source, $unused) {
        $found = idsMatching(false, $ids);

        return $found === [$source->id, $unused->id] ?: json_encode($found);
    });

    check('matchElement agrees with the query, element by element', function() use ($ids) {
        $rule = new IsUsedConditionRule(['value' => true]);
        $byQuery = idsMatching(true, $ids);
        $byMatch = [];

        foreach (Entry::find()->id($ids)->status(null)->orderBy(['elements.id' => SORT_ASC])->all() as $entry) {
            if ($rule->matchElement($entry)) {
                $byMatch[] = $entry->id;
            }
        }

        return $byQuery === $byMatch ?: json_encode([$byQuery, $byMatch]);
    });

    check('the rule is one EXISTS subquery on relations, not a list of ids', function() use ($ids) {
        $query = Entry::find()->id($ids);
        $rule = new IsUsedConditionRule(['value' => true]);
        $rule->modifyQuery($query);
        $sql = $query->createCommand()->getRawSql();

        return (str_contains($sql, 'EXISTS') && str_contains($sql, 'relations') && substr_count($sql, 'yarn_r') >= 2)
            ?: substr($sql, 0, 600);
    });

    check('a relation from a draft does not count', function() use ($ids, $unused, $source, $relate) {
        $draft = Craft::$app->getDrafts()->createDraft($source, 1);
        $relate($draft->id, $unused->id);
        $found = idsMatching(true, $ids);
        $count = Plugin::getInstance()->relations->usageCounts([$unused->id])[$unused->id];
        Craft::$app->getElements()->deleteElement($draft, true);

        return (!in_array($unused->id, $found, true) && $count === 0) ?: json_encode([$found, $count]);
    });

    check('a relation from an entry in the trash does not count', function() use ($ids, $unused, $make, $relate) {
        $gone = $make('Yarn usage — trashed source');
        $relate($gone->id, $unused->id);
        Craft::$app->getElements()->deleteElement($gone);
        $found = idsMatching(true, $ids);

        return !in_array($unused->id, $found, true) ?: json_encode($found);
    });

    check('an ignored field is ignored by the rule, the column and the panel alike', function() use ($ids, $used, $field) {
        configure(['ignoredFields' => [$field['handle']]]);
        $found = idsMatching(true, $ids);
        $count = Plugin::getInstance()->relations->usageCounts([$used->id])[$used->id];
        configure();

        return ($found === [] && $count === 0) ?: json_encode([$found, $count]);
    });

    check('applied to the Assets index it runs, and agrees with the batch count', function() use ($plugin) {
        $assetIds = Asset::find()->limit(50)->ids();

        if ($assetIds === []) {
            return true;
        }

        $assetIds = array_map('intval', $assetIds);
        $usedIds = idsMatching(true, $assetIds, Asset::class);
        $counted = array_keys(array_filter($plugin->relations->usageCounts($assetIds, Craft::$app->getSites()->getPrimarySite()->id)));
        sort($counted);

        return $usedIds === $counted ?: json_encode([$usedIds, $counted]);
    });

    section('Cleanup');

    check('the relation rows and entries are removed', function() use (&$made, &$relationRows) {
        foreach ($relationRows as [$s, $t]) {
            Db::delete(Table::RELATIONS, ['sourceId' => $s, 'targetId' => $t]);
        }

        foreach ($made as $entry) {
            $fresh = Entry::find()->id($entry->id)->status(null)->trashed(null)->one();

            if ($fresh) {
                Craft::$app->getElements()->deleteElement($fresh, true);
            }
        }

        return true;
    });
}

$plugin->setSettings($original);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
