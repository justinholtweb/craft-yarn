<?php
/**
 * The sidebar panel's counts, the nav settings and the MCP tools.
 *
 *     cd ~/Sites/plugin-testing && ddev exec php /var/www/craft-yarn/tests/integration/panel.php
 *
 * GitHub #2, the follow-up: a hard-coded link in a Table cell showed on Yarn's element screen and
 * not in the sidebar panel, because the panel read the relations table and nothing else. These
 * checks plant links and reference tags on purpose and prove the panel, the index count and the
 * full graph agree. Self-cleaning; settings changes are in memory only.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use justinholtweb\yarn\mcp\YarnTools;
use justinholtweb\yarn\models\Edge;
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

/** As in checks.php: the array goes in as an array, or the column double-encodes it. */
function writeContent(int $elementId, int $siteId, array $content): void
{
    Db::update(Table::ELEMENTS_SITES, ['content' => $content], ['elementId' => $elementId, 'siteId' => $siteId]);
    // Straight to the table is not an element save, so nothing bumped the stamp.
    Plugin::getInstance()->graph->invalidate();
}

/** @param array<int, array{id: int, kind: string}> $rows */
function idsOfKind(array $rows, string $kind): array
{
    return array_values(array_unique(array_map(
        fn(array $row) => $row['id'],
        array_filter($rows, fn(array $row) => $row['kind'] === $kind),
    )));
}

$site = Craft::$app->getSites()->getPrimarySite();
$admin = User::find()->admin()->status(null)->one();
Craft::$app->getUser()->setIdentity($admin);

$entrySection = null;

foreach (Craft::$app->getEntries()->getAllSections() as $candidate) {
    if ($candidate->type !== craft\models\Section::TYPE_SINGLE && $candidate->getEntryTypes()) {
        $entrySection = $candidate;
        break;
    }
}

$asset = (new Query())
    ->select(['a.id', 'a.filename', 'f.path'])
    ->from(['a' => Table::ASSETS])
    ->innerJoin(['f' => Table::VOLUMEFOLDERS], '[[f.id]] = [[a.folderId]]')
    ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[a.id]]')
    ->where(['e.dateDeleted' => null, 'e.draftId' => null, 'e.revisionId' => null])
    ->one();

$made = [];
$restore = [];

if ($entrySection === null || $asset === null) {
    echo "  ! skipped the panel checks — need a channel/structure section and at least one asset\n";
} else {
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

    $source = $make('Yarn panel — page with a button');
    $target = $make('Yarn panel — linked page');
    $bystander = $make('Yarn panel — mentions nothing');
    $assetId = (int)$asset['id'];
    $assetPath = '/media/downloads/' . ltrim(trim((string)$asset['path'], '/') . '/', '/') . $asset['filename'];
    $relations = $plugin->relations;

    // The issue's shape: a Table field, one column the label, one a root-relative link.
    $table = fn(string $link) => [['col1' => 'Download our policy document', 'col2' => $link]];

    section('GitHub #2 — a link in a Table cell, seen from the sidebar');

    configure(['sources' => [Settings::SOURCE_URLS]]);
    writeContent($source->id, $site->id, ['abc123' => $table($assetPath)]);

    check('the asset\'s panel counts the page that links to it', function() use ($relations, $assetId, $source, $site) {
        $ids = idsOfKind($relations->usages($assetId, $site->id), Edge::KIND_URL);

        return in_array($source->id, $ids, true) ?: 'pointed at by: ' . json_encode($relations->usages($assetId, $site->id));
    });

    check('the page\'s panel counts the asset it links to', function() use ($relations, $assetId, $source, $site) {
        $ids = idsOfKind($relations->uses($source->id, $site->id), Edge::KIND_URL);

        return in_array($assetId, $ids, true) ?: 'points at: ' . json_encode($relations->uses($source->id, $site->id));
    });

    check('the rendered panel says so', function() use ($plugin, $assetId) {
        $element = Craft::$app->getElements()->getElementById($assetId);
        $method = new ReflectionMethod($plugin, 'elementPanelHtml');
        $html = $method->invoke($plugin, $element);

        return (str_contains($html, 'break their links') && preg_match('/<b>\s*[1-9]\d*\s*<\/b>\s*pointed at by/', $html) === 1)
            ?: 'panel: ' . trim(strip_tags($html));
    });

    check('the index column\'s count includes the link, and agrees with the one-at-a-time count', function() use ($relations, $assetId, $source, $bystander, $site) {
        $ids = [$assetId, $source->id, $bystander->id];
        $batch = $relations->usageCounts($ids, $site->id);
        $single = array_combine($ids, array_map(fn($id) => $relations->countUsages($id, $site->id), $ids));

        return ($batch === $single && $batch[$assetId] >= 1 && $batch[$bystander->id] === 0)
            ?: json_encode([$batch, $single]);
    });

    check('the panel agrees with the full graph about who links to the asset', function() use ($plugin, $relations, $assetId, $site) {
        $graph = $plugin->graph->get($site->id, false);
        $fromGraph = [];

        foreach ($graph->incoming($assetId) as $edge) {
            if ($edge->kind === Edge::KIND_URL) {
                $fromGraph[] = $edge->from;
            }
        }

        $fromPanel = idsOfKind($relations->usages($assetId, $site->id), Edge::KIND_URL);
        sort($fromGraph);
        sort($fromPanel);

        return array_values(array_unique($fromGraph)) === $fromPanel ?: json_encode(['graph' => $fromGraph, 'panel' => $fromPanel]);
    });

    check('a link to a page, not an asset, is counted the same way', function() use ($relations, $source, $target, $site, $table) {
        $uri = (string)(new Query())->select(['uri'])->from([Table::ELEMENTS_SITES])
            ->where(['elementId' => $target->id, 'siteId' => $site->id])->scalar();

        if ($uri === '' || $uri === '__home__') {
            return true; // the borrowed section has no URLs
        }

        writeContent($source->id, $site->id, ['abc123' => $table('/' . $uri)]);

        return in_array($source->id, idsOfKind($relations->usages($target->id, $site->id), Edge::KIND_URL), true)
            ?: 'pointed at by: ' . json_encode($relations->usages($target->id, $site->id));
    });

    check('with the link source off, links are not counted', function() use ($relations, $source, $target, $site) {
        configure(['sources' => []]);
        $rows = $relations->usages($target->id, $site->id);
        configure(['sources' => [Settings::SOURCE_URLS]]);

        return idsOfKind($rows, Edge::KIND_URL) === [] ?: json_encode($rows);
    });

    check('the cached answer is replaced after the next save', function() use ($relations, $source, $assetId, $site, $table, $assetPath) {
        writeContent($source->id, $site->id, ['abc123' => $table($assetPath)]);
        $before = in_array($source->id, idsOfKind($relations->usages($assetId, $site->id), Edge::KIND_URL), true);

        writeContent($source->id, $site->id, ['abc123' => $table('https://example.org/elsewhere.pdf')]);
        $after = in_array($source->id, idsOfKind($relations->usages($assetId, $site->id), Edge::KIND_URL), true);

        return ($before && !$after) ?: json_encode(compact('before', 'after'));
    });

    section('Reference tags');

    configure(['sources' => [Settings::SOURCE_REF_TAGS]]);

    check('{entry:ID:url} counts on both ends', function() use ($relations, $source, $target, $site) {
        writeContent($source->id, $site->id, ['abc123' => '<p><a href="{entry:' . $target->id . ':url}">Go</a></p>']);

        $in = in_array($source->id, idsOfKind($relations->usages($target->id, $site->id), Edge::KIND_REF), true);
        $out = in_array($target->id, idsOfKind($relations->uses($source->id, $site->id), Edge::KIND_REF), true);

        return ($in && $out) ?: json_encode(compact('in', 'out'));
    });

    check('{entry:UID} counts too', function() use ($relations, $source, $target, $site) {
        writeContent($source->id, $site->id, ['abc123' => 'See {entry:' . $target->uid . '}.']);

        return in_array($source->id, idsOfKind($relations->usages($target->id, $site->id), Edge::KIND_REF), true)
            ?: json_encode($relations->usages($target->id, $site->id));
    });

    check('a longer id that starts the same way is not mistaken for it', function() use ($relations, $source, $target, $site) {
        writeContent($source->id, $site->id, ['abc123' => '{entry:' . $target->id . '9:url} and 10:' . $target->id . ':30']);

        return idsOfKind($relations->usages($target->id, $site->id), Edge::KIND_REF) === []
            ?: json_encode($relations->usages($target->id, $site->id));
    });

    section('Drafts and nesting');

    check('a link that exists only in a draft is not counted', function() use ($relations, $source, $target, $site, &$made) {
        writeContent($source->id, $site->id, []);
        $draft = Craft::$app->getDrafts()->createDraft($source, $source->getAuthorId() ?? 1, 'Yarn panel test');
        $made[] = $draft;
        writeContent($draft->id, $site->id, ['abc123' => '{entry:' . $target->id . ':url}']);

        $ids = array_column($relations->usages($target->id, $site->id), 'id');

        return (!in_array($draft->id, $ids, true) && !in_array($source->id, $ids, true)) ?: json_encode($ids);
    });

    check('a link inside a nested block is counted as used by the page that owns it', function() use ($relations, $target, $site, &$restore) {
        $pair = (new Query())
            ->select(['eo.elementId', 'eo.ownerId'])
            ->from(['eo' => Table::ELEMENTS_OWNERS])
            ->innerJoin(['n' => Table::ELEMENTS], '[[n.id]] = [[eo.elementId]]')
            ->innerJoin(['o' => Table::ELEMENTS], '[[o.id]] = [[eo.ownerId]]')
            ->innerJoin(['es' => Table::ELEMENTS_SITES], '[[es.elementId]] = [[eo.elementId]] and [[es.siteId]] = ' . (int)$site->id)
            ->where([
                'n.draftId' => null, 'n.revisionId' => null, 'n.dateDeleted' => null,
                'o.draftId' => null, 'o.revisionId' => null, 'o.dateDeleted' => null,
            ])
            ->andWhere(['not exists', (new Query())->from(['up' => Table::ELEMENTS_OWNERS])->where('[[up.elementId]] = [[eo.ownerId]]')])
            ->one();

        if ($pair === null) {
            return true; // no nested content on this install
        }

        $block = (int)$pair['elementId'];
        $page = (int)$pair['ownerId'];
        $stored = (new Query())->select(['content'])->from([Table::ELEMENTS_SITES])
            ->where(['elementId' => $block, 'siteId' => $site->id])->scalar();
        $restore[] = [$block, $site->id, $stored];

        $content = is_string($stored) ? (json_decode($stored, true) ?: []) : [];
        $content['yarnPanelTest'] = '{entry:' . $target->id . ':url}';
        writeContent($block, $site->id, $content);

        $in = $relations->usages($target->id, $site->id);
        $out = $relations->uses($page, $site->id);
        $rolledUp = in_array($page, idsOfKind($in, Edge::KIND_REF), true)
            && !in_array($block, idsOfKind($in, Edge::KIND_REF), true)
            && in_array($target->id, idsOfKind($out, Edge::KIND_REF), true);

        return $rolledUp ?: json_encode(['block' => $block, 'page' => $page, 'in' => $in, 'out' => $out]);
    });

    section('MCP tools, called directly');

    configure(['sources' => [Settings::SOURCE_URLS, Settings::SOURCE_REF_TAGS]]);
    writeContent($source->id, $site->id, ['abc123' => $table($assetPath), 'def456' => '{entry:' . $target->id . ':url}']);
    $tools = new YarnTools();

    check('yarn_element_relations lists the page under the asset\'s "pointed at by"', function() use ($tools, $assetId, $source) {
        $result = $tools->elementRelations($assetId);
        $ids = [];

        foreach ($result['pointedAtBy'] as $group) {
            foreach ($group['elements'] as $element) {
                $ids[] = $element['node']['id'];
            }
        }

        return (in_array($source->id, $ids, true) && isset($result['caveat'], $result['deleteImpact']))
            ?: json_encode($result['pointedAtBy']);
    });

    check('yarn_findings returns findings with a detail sentence each, and honours the limit', function() use ($tools) {
        $result = $tools->findings(null, [], 3);

        foreach ($result['findings'] as $finding) {
            if (($finding['detail'] ?? '') === '') {
                return 'a finding without a detail sentence: ' . json_encode($finding);
            }
        }

        return (count($result['findings']) <= 3 && $result['truncated'] === ($result['total'] > 3)) ?: json_encode($result);
    });

    check('yarn_unused_assets does not list an asset a page links to', function() use ($tools, $assetId) {
        $ids = array_column($tools->unusedAssets(null, null, 500)['assets'], 'id');

        return !in_array($assetId, $ids, true) ?: 'listed as unused';
    });

    check('yarn_path finds the hop from the page to the asset', function() use ($tools, $source, $assetId) {
        $result = $tools->path($source->id, $assetId);

        return ($result['found'] && $result['hops'] === 1) ?: json_encode($result);
    });

    check('on this Craft (' . Craft::$app->getVersion() . ') the tools register only if the MCP service exists', function() {
        $exists = class_exists('craft\\services\\Mcp');

        if (!$exists) {
            return true; // nothing to register against, and nothing was
        }

        $types = Craft::$app->get('mcp')->getCapabilityRegistry()->types();

        return in_array(YarnTools::class, $types, true) ?: 'not registered: ' . json_encode($types);
    });

    /**
     * Talks to `php craft mcp/serve` over stdio, as a real client would, and returns the names of
     * every tool it lists (following pagination) plus the result of calling `$call`.
     *
     * @return array{tools: string[], call: array|null}
     */
    $stdio = function(int $userId, ?array $call = null): array {
        $proc = proc_open(['php', 'craft', 'mcp/serve', "--user=$userId"], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, getcwd());
        $send = fn(array $message) => fwrite($pipes[0], json_encode($message) . "\n");
        $read = function(int $id) use ($pipes): ?array {
            while (($line = fgets($pipes[1])) !== false) {
                $message = json_decode($line, true);

                if (is_array($message) && ($message['id'] ?? null) === $id) {
                    return $message;
                }
            }

            return null;
        };

        $send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => (object)[], 'clientInfo' => ['name' => 'yarn-test', 'version' => '1'],
        ]]);
        $read(1);
        $send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $tools = [];
        $cursor = null;
        $id = 2;

        do {
            $send(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/list', 'params' => $cursor ? ['cursor' => $cursor] : (object)[]]);
            $page = $read($id++);
            $tools = array_merge($tools, array_column($page['result']['tools'] ?? [], 'name'));
            $cursor = $page['result']['nextCursor'] ?? null;
        } while ($cursor !== null);

        $result = null;

        if ($call !== null) {
            $send(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'tools/call', 'params' => $call]);
            $result = $read(99);
        }

        fclose($pipes[0]);
        proc_close($proc);

        return ['tools' => $tools, 'call' => $result];
    };

    check('through the stdio server, an admin sees all four tools and can call one', function() use ($stdio, $assetId) {
        if (!class_exists('craft\\services\\Mcp')) {
            return true;
        }

        $admin = User::find()->admin()->status(null)->one();
        $result = $stdio($admin->id, ['name' => 'yarn_element_relations', 'arguments' => ['elementId' => $assetId]]);
        $mine = array_values(array_filter($result['tools'], fn($n) => str_starts_with($n, 'yarn_')));
        sort($mine);
        $text = $result['call']['result']['content'][0]['text'] ?? '';

        return ($mine === ['yarn_element_relations', 'yarn_findings', 'yarn_path', 'yarn_unused_assets']
            && ($result['call']['result']['isError'] ?? false) === false
            // A separate process with the *stored* settings, so the in-memory link source isn't on
            // there: check the element came back, not who links to it.
            && str_contains($text, '"id": ' . $assetId))
            ?: json_encode(['tools' => $mine, 'call' => $result['call']]);
    });

    check('through the stdio server, a user without Yarn’s permission sees none of them', function() use ($stdio) {
        if (!class_exists('craft\\services\\Mcp')) {
            return true;
        }

        $without = null;

        foreach (User::find()->admin(false)->status('active')->limit(50)->all() as $candidate) {
            if (!$candidate->can(Plugin::PERMISSION_VIEW)) {
                $without = $candidate;
                break;
            }
        }

        if ($without === null) {
            return true; // every user here can see Yarn
        }

        $result = $stdio($without->id, ['name' => 'yarn_findings', 'arguments' => (object)[]]);
        $mine = array_filter($result['tools'], fn($n) => str_starts_with($n, 'yarn_'));

        return ($mine === [] && isset($result['call']['error'])) ?: json_encode(['tools' => array_values($mine), 'call' => $result['call']]);
    });
}

section('Nav name and icon');

check('blank settings leave the nav item as Yarn, with its own icon', function() use ($plugin) {
    configure(['navLabel' => '', 'navIcon' => '']);
    $item = $plugin->getCpNavItem();

    return ($item['label'] === $plugin->name && str_ends_with((string)$item['icon'], 'icon-mask.svg')) ?: json_encode($item);
});

check('a name and an icon replace them', function() use ($plugin) {
    configure(['navLabel' => 'Content map', 'navIcon' => 'diagram-project']);
    $item = $plugin->getCpNavItem();
    configure();

    return ($item['label'] === 'Content map' && $item['icon'] === 'diagram-project') ?: json_encode($item);
});

check('an icon that is a path, not a name, does not validate', function() {
    $settings = new Settings();
    $settings->setAttributes(['navIcon' => '../../etc/passwd.svg'], false);

    return (!$settings->validate(['navIcon']) && $settings->hasErrors('navIcon')) ?: 'accepted a path';
});

// ============================================================================ cleanup

section('Cleanup');

configure();

foreach ($restore as [$elementId, $siteId, $content]) {
    Db::update(Table::ELEMENTS_SITES, ['content' => is_string($content) ? json_decode($content, true) : $content], ['elementId' => $elementId, 'siteId' => $siteId]);
}

foreach (array_reverse($made) as $element) {
    Craft::$app->getElements()->deleteElement($element, true);
}

$plugin->graph->invalidate();
echo "  removed " . count($made) . " element(s), restored " . count($restore) . " content row(s)\n";

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
