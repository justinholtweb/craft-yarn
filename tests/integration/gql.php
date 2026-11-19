<?php
/**
 * GraphQL: the schema component, the queries, and masking by schema scope.
 *
 *     cd ~/Sites/plugin-testing && ddev exec php /var/www/craft-yarn/tests/integration/gql.php
 *
 * Runs real queries through Craft's GraphQL service against schemas built in memory — nothing is
 * saved to project config. Plants one page linking to one asset, and proves what a schema can and
 * can't see. Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
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

/**
 * A schema is just its scope here: each query runs in its own process (see gql-run.php), built
 * from scratch the way a real request builds it.
 *
 * @param string[] $scope
 * @return string[]
 */
function schema(array $scope): array
{
    return $scope;
}

/** The in-memory settings overrides each query process should run with. */
$gqlSettings = [];

/** @param string[] $scope */
function gql(array $scope, string $query, array $variables = []): array
{
    global $gqlSettings;

    $proc = proc_open(['php', __DIR__ . '/gql-run.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, getcwd());
    fwrite($pipes[0], json_encode(['scope' => $scope, 'query' => $query, 'variables' => (object)$variables, 'settings' => $gqlSettings]));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);

    return json_decode($out, true) ?? ['errors' => [['message' => 'runner failed: ' . trim($out . ' ' . $err)]]];
}

function errors(array $result): string
{
    return implode(' | ', array_map(fn(array $e) => $e['message'] ?? json_encode($e), $result['errors'] ?? []));
}

$sites = Craft::$app->getSites();
$siteScopes = array_map(fn($s) => "sites.$s->uid:read", $sites->getAllSites());
$sectionScopes = array_map(fn($s) => "sections.$s->uid:read", Craft::$app->getEntries()->getAllSections());
$volumeScopes = array_map(fn($v) => "volumes.$v->uid:read", Craft::$app->getVolumes()->getAllVolumes());
$site = $sites->getPrimarySite();

section('Registration');

check('Yarn offers a schema component', function() {
    $components = Craft::$app->getGql()->getAllSchemaComponents();

    foreach ($components['queries'] ?? [] as $group) {
        if (isset($group['yarn:read'])) {
            return true;
        }
    }

    return 'no yarn:read in ' . json_encode(array_keys($components['queries'] ?? []));
});

check('a schema without it has no Yarn queries', function() use ($siteScopes) {
    $result = gql(schema($siteScopes), '{ yarnUsageCount(id: 1) }');

    return str_contains(errors($result), 'yarnUsageCount') ?: json_encode($result);
});

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

if ($entrySection === null || $asset === null) {
    echo "  ! skipped the query checks — need a channel/structure section and at least one asset\n";
} else {
    $page = new Entry();
    $page->sectionId = $entrySection->id;
    $page->typeId = $entrySection->getEntryTypes()[0]->id;
    $page->title = 'Yarn GraphQL — page with a download';

    if (!Craft::$app->getElements()->saveElement($page)) {
        throw new RuntimeException(json_encode($page->getErrors()));
    }

    $made[] = $page;
    $assetId = (int)$asset['id'];
    $link = '/media/' . ltrim(trim((string)$asset['path'], '/') . '/', '/') . $asset['filename'];

    $gqlSettings = ['sources' => [Settings::SOURCE_URLS, Settings::SOURCE_REF_TAGS]];
    configure($gqlSettings);
    Db::update(Table::ELEMENTS_SITES, ['content' => ['abc123' => [['col1' => 'Download', 'col2' => $link]]]], ['elementId' => $page->id, 'siteId' => $site->id]);
    $plugin->graph->invalidate();

    $full = schema(array_merge(['yarn:read'], $siteScopes, $sectionScopes, $volumeScopes));

    $elementQuery = 'query($id: Int!) { yarnElement(id: $id) {
        site element { id kind label health editUrl }
        pointedAtBy { field kind kindLabel elements { via node { id label kind } } }
        pointedAtByCount pointsAtCount
        deleteImpact { direct { id label } indirect { hops node { id } } }
        caveat
    } }';

    $usedBy = function(array $result): array {
        $found = [];

        foreach ($result['data']['yarnElement']['pointedAtBy'] ?? [] as $group) {
            foreach ($group['elements'] as $element) {
                $found[$element['node']['id']] = $element['node']['label'];
            }
        }

        return $found;
    };

    section('Queries, with a schema that can read everything');

    check('yarnElement lists the page under the asset’s pointedAtBy, by its title', function() use ($full, $elementQuery, $assetId, $page, $usedBy) {
        $result = gql($full, $elementQuery, ['id' => $assetId]);
        $found = $usedBy($result);

        return (($found[$page->id] ?? null) === $page->title && $result['data']['yarnElement']['caveat'] !== '')
            ?: errors($result) . json_encode($result['data'] ?? null);
    });

    check('yarnUsageCount agrees with the Relations panel', function() use ($full, $assetId, $plugin, $site) {
        $result = gql($full, 'query($id: Int!) { yarnUsageCount(id: $id) }', ['id' => $assetId]);
        $expected = $plugin->relations->countUsages($assetId, $site->id);

        return (($result['data']['yarnUsageCount'] ?? null) === $expected && $expected >= 1) ?: errors($result) . json_encode([$result['data'] ?? null, $expected]);
    });

    check('yarnFindings returns a tally and findings with what to do about each', function() use ($full) {
        $result = gql($full, '{ yarnFindings(limit: 3) { total truncated tally { check name count } findings { check severity title detail context subject { id } } } }');
        $data = $result['data']['yarnFindings'] ?? null;

        if ($data === null) {
            return errors($result);
        }

        foreach ($data['findings'] as $finding) {
            if ($finding['detail'] === '') {
                return 'a finding without a detail sentence';
            }
        }

        return (count($data['findings']) <= 3 && count($data['tally']) === 8 && $data['truncated'] === ($data['total'] > 3)) ?: json_encode($data);
    });

    check('an unknown check is a readable error, not a crash', function() use ($full) {
        $result = gql($full, '{ yarnFindings(checks: ["nope"]) { total } }');

        return str_contains(errors($result), 'Unknown check(s): nope') ?: json_encode($result);
    });

    check('yarnUnusedAssets does not list the asset the page links to', function() use ($full, $assetId) {
        $result = gql($full, '{ yarnUnusedAssets(limit: 500) { total assets { id } } }');
        $ids = array_column($result['data']['yarnUnusedAssets']['assets'] ?? [], 'id');

        return (!isset($result['errors']) && !in_array($assetId, $ids, true)) ?: errors($result) ?: 'listed as unused';
    });

    check('yarnPath finds the hop from the page to the asset', function() use ($full, $page, $assetId) {
        $result = gql($full, 'query($a: Int!, $b: Int!) { yarnPath(fromId: $a, toId: $b) { found hops steps { from to kind kindLabel } } }', ['a' => $page->id, 'b' => $assetId]);
        $data = $result['data']['yarnPath'] ?? null;

        return ($data !== null && $data['found'] && $data['hops'] === 1 && $data['steps'][0]['kind'] === 'url') ?: errors($result) . json_encode($data);
    });

    section('Masking by schema scope');

    check('without the section, the page is “Restricted element #id” — and still counted', function() use ($siteScopes, $volumeScopes, $elementQuery, $assetId, $page, $usedBy) {
        $result = gql(schema(array_merge(['yarn:read'], $siteScopes, $volumeScopes)), $elementQuery, ['id' => $assetId]);
        $found = $usedBy($result);

        return (isset($found[$page->id]) && str_starts_with($found[$page->id], 'Restricted element') && !in_array($page->title, $found, true))
            ?: errors($result) . json_encode($found);
    });

    check('without the volume, the asset itself is masked', function() use ($siteScopes, $sectionScopes, $elementQuery, $assetId, $asset) {
        $result = gql(schema(array_merge(['yarn:read'], $siteScopes, $sectionScopes)), $elementQuery, ['id' => $assetId]);
        $label = $result['data']['yarnElement']['element']['label'] ?? null;

        return ($label !== null && $label !== $asset['filename'] && str_starts_with($label, 'Restricted element')) ?: errors($result) . json_encode($label);
    });

    check('a broken reference in a page the schema can’t read doesn’t leak its text', function() use ($siteScopes, $volumeScopes, $full, $page, $site, $plugin, $link) {
        Db::update(Table::ELEMENTS_SITES, ['content' => [
            'abc123' => [['col1' => 'Download', 'col2' => $link]],
            'def456' => '<a href="{entry:987654321:url}">secret-slug-yarn</a>',
        ]], ['elementId' => $page->id, 'siteId' => $site->id]);
        $plugin->graph->invalidate();

        $query = '{ yarnFindings(checks: ["unresolvedReferences"], limit: 500) { findings { title context subject { id label } } } }';
        $open = json_encode(gql($full, $query));
        $closed = json_encode(gql(schema(array_merge(['yarn:read'], $siteScopes, $volumeScopes)), $query));

        return (str_contains($open, '987654321') && !str_contains($closed, '987654321') && str_contains($closed, 'restricted element'))
            ?: 'full schema saw it: ' . var_export(str_contains($open, '987654321'), true) . '; restricted: ' . substr($closed, 0, 400);
    });

    check('a schema that may read one user group does not reveal other users', function() use ($plugin, $site) {
        $graph = $plugin->graph->get($site->id);
        $userIds = array_keys(array_filter($graph->nodes, fn($n) => $n->kind === 'user'));
        $group = Craft::$app->getUserGroups()->getAllGroups()[0] ?? null;

        if ($userIds === [] || $group === null) {
            return true; // no users in the graph, or no groups to scope to
        }

        $one = new craft\models\GqlSchema(['scope' => ["usergroups.$group->uid:read"]]);
        $everyone = new craft\models\GqlSchema(['scope' => ['usergroups.everyone:read']]);
        $label = fn(craft\models\GqlSchema $schema) => $plugin->graph->forSchema($graph, $schema)->node($userIds[0])->label;

        return (str_starts_with($label($one), 'Restricted element') && !str_starts_with($label($everyone), 'Restricted element'))
            ?: json_encode([$label($one), $label($everyone)]);
    });

    check('an unresolved reference is judged by the page it was found on, and fails closed', function() use ($plugin, $site, $page) {
        $everything = ['usergroups.everyone:read'];

        foreach ([
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'volumes' => Craft::$app->getVolumes()->getAllVolumes(),
            'categorygroups' => Craft::$app->getCategories()->getAllGroups(),
            'taggroups' => Craft::$app->getTags()->getAllTagGroups(),
            'globalsets' => Craft::$app->getGlobals()->getAllSets(),
        ] as $scope => $models) {
            foreach ($models as $model) {
                $everything[] = "$scope.$model->uid:read";
            }
        }

        $graph = clone $plugin->graph->get($site->id);
        $graph->unresolved = [
            // Found in a nested block (id not a node) on a page the schema can read.
            ['elementId' => 987654320, 'ownerId' => $page->id, 'reference' => '/kept-yarn', 'type' => 'url'],
            // Found on something that isn't in the graph at all.
            ['elementId' => 987654321, 'ownerId' => 987654321, 'reference' => '/orphan-yarn', 'type' => 'url'],
            // From a graph cached before ownerId existed, in a nested block.
            ['elementId' => 987654322, 'reference' => '/legacy-yarn', 'type' => 'url'],
        ];

        $refs = fn(array $scope) => array_column($plugin->graph->forSchema($graph, new craft\models\GqlSchema(['scope' => $scope]))->unresolved, 'reference');
        $all = $refs($everything);
        $withoutSection = $refs(array_values(array_filter($everything, fn($s) => !str_starts_with($s, 'sections.'))));
        $finding = null;

        foreach (Plugin::getInstance()->findings->run($graph, ['unresolvedReferences']) as $f) {
            if (($f->context['reference'] ?? null) === '/kept-yarn') {
                $finding = $f;
            }
        }

        return ($all[0] === '/kept-yarn' && $all[1] !== '/orphan-yarn' && $all[2] !== '/legacy-yarn'
            && $withoutSection[0] !== '/kept-yarn'
            && $finding?->subject?->id === $page->id)
            ?: json_encode(['all' => $all, 'withoutSection' => $withoutSection, 'subject' => $finding?->subject?->id]);
    });

    check('without any site, every Yarn query refuses', function() use ($sectionScopes) {
        $result = gql(schema(array_merge(['yarn:read'], $sectionScopes)), '{ yarnUsageCount(id: 1) }');

        return str_contains(errors($result), "can't query any site") ?: json_encode($result);
    });

    check('asking for a site the schema can’t read is refused by name', function() use ($site) {
        $result = gql(schema(['yarn:read']), 'query($s: String) { yarnFindings(site: $s) { total } }', ['s' => $site->handle]);

        return str_contains(errors($result), "can't query the {$site->handle} site") ?: json_encode($result);
    });
}

// ============================================================================ cleanup

section('Cleanup');

configure();

foreach (array_reverse($made) as $element) {
    Craft::$app->getElements()->deleteElement($element, true);
}

$plugin->graph->invalidate();
echo "  removed " . count($made) . " element(s)\n";

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
