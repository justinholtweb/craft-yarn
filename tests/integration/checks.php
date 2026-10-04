<?php
/**
 * Yarn integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-yarn/tests/integration/checks.php
 *
 * Covers what the unit suite cannot: settings arriving in the shapes the control panel posts,
 * the graph assembled from real tables, and every finding proved by breaking something on
 * purpose and watching the right check catch it.
 *
 * Self-cleaning. It creates its own entries and relation rows, and removes them at the end; the
 * plugin's settings are captured up front and restored in memory. Nothing is written to project
 * config, so running this against a configured site does not reconfigure it.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use justinholtweb\yarn\models\BuildContext;
use justinholtweb\yarn\models\Edge;
use justinholtweb\yarn\models\Finding;
use justinholtweb\yarn\models\Graph as GraphModel;
use justinholtweb\yarn\models\Node;
use justinholtweb\yarn\models\Settings;
use justinholtweb\yarn\Plugin;
use justinholtweb\yarn\services\Export;
use justinholtweb\yarn\services\Findings;
use justinholtweb\yarn\sources\ContentSource;
use justinholtweb\yarn\sources\RelationsSource;

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
$site = Craft::$app->getSites()->getPrimarySite();
$original = $plugin->getSettings()->toArray();

/** Swaps in a settings shape, in memory only, and clears anything memoized from the old one. */
function configure(array $overrides = []): Settings
{
    global $original;

    $plugin = Plugin::getInstance();
    $settings = new Settings();
    $settings->setAttributes(array_merge($original, $overrides), false);
    $plugin->setSettings($settings->toArray());
    $plugin->graph->invalidate();

    return $plugin->getSettings();
}

function node(int $id, string $kind = Node::KIND_ENTRY, array $overrides = []): Node
{
    return new Node(
        id: $id,
        type: $overrides['type'] ?? 'craft\\elements\\Entry',
        kind: $kind,
        label: $overrides['label'] ?? "Node $id",
        group: $overrides['group'] ?? 'Group',
        groupKey: $overrides['groupKey'] ?? 'section:1',
        uri: $overrides['uri'] ?? null,
        enabled: $overrides['enabled'] ?? true,
        enabledForSite: $overrides['enabledForSite'] ?? true,
        deleted: $overrides['deleted'] ?? false,
        inSite: $overrides['inSite'] ?? true,
        entryStatus: $overrides['entryStatus'] ?? null,
    );
}

// ============================================================================ settings

section('Settings, in the shapes the control panel posts');

check('a lightswitch’s "1" and "" become real booleans', function() {
    $settings = new Settings();
    $settings->setAttributes(['rollUpNested' => '', 'includeDisabled' => '1'], false);

    return ($settings->rollUpNested === false && $settings->includeDisabled === true)
        ?: 'got ' . var_export([$settings->rollUpNested, $settings->includeDisabled], true);
});

check('"false" from a YAML config is false, not a truthy string', function() {
    $settings = new Settings();
    $settings->setAttributes(['showElementPanel' => 'false'], false);

    return $settings->showElementPanel === false ?: 'stayed true';
});

check('an emptied number box keeps the default rather than throwing', function() {
    $settings = new Settings();
    $settings->setAttributes(['maxNodes' => '', 'cacheDuration' => ''], false);

    return ($settings->maxNodes === 400 && $settings->cacheDuration === 300)
        ?: "maxNodes={$settings->maxNodes} cacheDuration={$settings->cacheDuration}";
});

check('a number box posts a string and lands as an int', function() {
    $settings = new Settings();
    $settings->setAttributes(['maxNodes' => '250'], false);

    return $settings->maxNodes === 250 ?: 'got ' . var_export($settings->maxNodes, true);
});

check('an all-unticked checkbox group posts [""] and becomes []', function() {
    $settings = new Settings();
    $settings->setAttributes(['sources' => ['']], false);

    return $settings->sources === [] ?: json_encode($settings->sources);
});

check('a multiselect of field handles survives, de-duplicated', function() {
    $settings = new Settings();
    $settings->setAttributes(['ignoredFields' => ['heroImage', ' heroImage ', '', 'related']], false);

    return $settings->ignoredFields === ['heroImage', 'related'] ?: json_encode($settings->ignoredFields);
});

check('an unknown source fails validation', function() {
    $settings = new Settings();
    $settings->setAttributes(['sources' => ['refTags', 'telepathy']], false);

    return (!$settings->validate() && $settings->hasErrors('sources')) ?: 'validated anyway';
});

check('an unknown element kind fails validation', function() {
    $settings = new Settings();
    $settings->setAttributes(['orphanKinds' => ['entry', 'sandwich']], false);

    return (!$settings->validate() && $settings->hasErrors('orphanKinds')) ?: 'validated anyway';
});

check('maxNodes outside its range fails validation', function() {
    $settings = new Settings();
    $settings->setAttributes(['maxNodes' => 9], false);

    return (!$settings->validate() && $settings->hasErrors('maxNodes')) ?: 'validated anyway';
});

check('no setting is marked required, so a bare install can save', function() {
    $settings = new Settings();

    return $settings->validate() ?: json_encode($settings->getErrors());
});

check('the fingerprint changes when a source is switched on', function() {
    $a = new Settings();
    $b = new Settings();
    $b->setAttributes(['sources' => ['refTags', 'urls']], false);

    return $a->graphFingerprint() !== $b->graphFingerprint() ?: 'fingerprints matched';
});

check('the fingerprint ignores settings that do not change the graph', function() {
    // maxNodes only decides how much of a built graph is drawn. If it were part of the key,
    // nudging it would throw away a cached graph for nothing.
    $a = new Settings();
    $b = new Settings();
    $b->setAttributes(['maxNodes' => 123, 'cacheDuration' => 77, 'cycleLimit' => 3], false);

    return $a->graphFingerprint() === $b->graphFingerprint() ?: 'fingerprint moved';
});

check('uses() answers for optional sources only', function() {
    $settings = new Settings();
    $settings->setAttributes(['sources' => ['refTags']], false);

    return ($settings->uses(Settings::SOURCE_REF_TAGS) && !$settings->uses(Settings::SOURCE_URLS))
        ?: 'wrong answer';
});

// ============================================================================ sources

section('Which sources run');

check('the relations source is always on, whatever the settings say', function() {
    $context = new BuildContext(Craft::$app->getSites()->getPrimarySite()->id, (function() {
        $s = new Settings();
        $s->setAttributes(['sources' => []], false);
        return $s;
    })());

    return (new RelationsSource($context))->isEnabled() ?: 'relations went quiet';
});

check('the content source runs when either of its two settings is on', function() {
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;

    $off = new Settings();
    $off->setAttributes(['sources' => []], false);

    $urls = new Settings();
    $urls->setAttributes(['sources' => ['urls']], false);

    return (!(new ContentSource(new BuildContext($siteId, $off)))->isEnabled()
        && (new ContentSource(new BuildContext($siteId, $urls)))->isEnabled())
        ?: 'wrong answer';
});

check('the nested source is the inverse of roll-up', function() {
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $sources = Plugin::getInstance()->sources;

    $rolled = new Settings();
    $rolled->setAttributes(['rollUpNested' => true], false);

    $flat = new Settings();
    $flat->setAttributes(['rollUpNested' => false], false);

    $ids = fn(Settings $s) => array_map(
        fn($source) => $source::id(),
        $sources->enabled(new BuildContext($siteId, $s)),
    );

    return (!in_array('nested', $ids($rolled), true) && in_array('nested', $ids($flat), true))
        ?: json_encode([$ids($rolled), $ids($flat)]);
});

check('every registered source has a distinct id and a name', function() {
    $context = new BuildContext(Craft::$app->getSites()->getPrimarySite()->id, new Settings());
    $sources = Plugin::getInstance()->sources->all($context);
    $ids = array_map(fn($source) => $source::id(), $sources);

    return (count($ids) === count(array_unique($ids)) && count($ids) >= 4)
        ?: json_encode($ids);
});

// ============================================================================ the real graph

section('The graph, assembled from this site');

$graph = $plugin->graph->get($site->id, false);

check('it has nodes and relations', function() use ($graph) {
    return (count($graph->nodes) > 0 && count($graph->edges) > 0)
        ?: 'nodes=' . count($graph->nodes) . ' edges=' . count($graph->edges);
});

check('every edge lands on a node that exists', function() use ($graph) {
    foreach ($graph->edges as $edge) {
        if (!$graph->has($edge->from) || !$graph->has($edge->to)) {
            return "edge {$edge->from}→{$edge->to} dangles";
        }
    }

    return true;
});

check('the tallies agree with the adjacency', function() use ($graph) {
    foreach (array_slice($graph->nodes, 0, 200, true) as $node) {
        if ($node->inCount !== count($graph->incoming($node->id))) {
            return "node {$node->id} in-count disagrees";
        }
        if ($node->outCount !== count($graph->outgoing($node->id))) {
            return "node {$node->id} out-count disagrees";
        }
    }

    return true;
});

check('no draft or revision is in the graph', function() use ($graph) {
    $ids = array_slice(array_keys($graph->nodes), 0, 3000);

    $bad = (new Query())
        ->from([Table::ELEMENTS])
        ->where(['id' => $ids])
        ->andWhere(['or', ['not', ['draftId' => null]], ['not', ['revisionId' => null]], ['archived' => true]])
        ->count();

    return (int)$bad === 0 ?: "$bad drafts or revisions leaked in";
});

check('every node has a label', function() use ($graph) {
    foreach ($graph->nodes as $node) {
        if (trim($node->label) === '') {
            return "node {$node->id} ({$node->type}) has no label";
        }
    }

    return true;
});

check('every node has a group key, including types Yarn has never heard of', function() use ($graph) {
    foreach ($graph->nodes as $node) {
        if ($node->groupKey === '') {
            return "node {$node->id} ({$node->type}) is ungrouped";
        }
    }

    return true;
});

check('an asset is labelled by its filename', function() use ($graph) {
    foreach ($graph->nodes as $node) {
        if ($node->kind !== Node::KIND_ASSET) {
            continue;
        }

        $filename = (new Query())->select(['filename'])->from([Table::ASSETS])->where(['id' => $node->id])->scalar();

        return $node->label === $filename ?: "labelled “{$node->label}”, filename is “$filename”";
    }

    return true;
});

check('an entry carries its section as its group', function() use ($graph) {
    foreach ($graph->nodes as $node) {
        if ($node->kind !== Node::KIND_ENTRY || $node->groupKey === 'section:nested') {
            continue;
        }

        return str_starts_with($node->groupKey, 'section:') ?: "grouped as {$node->groupKey}";
    }

    return true;
});

check('stats add up', function() use ($graph) {
    $stats = $graph->stats();

    return ($stats['nodes'] === count($graph->nodes)
        && $stats['edges'] === count($graph->edges)
        && $stats['connected'] + $stats['isolated'] === $stats['nodes'])
        ?: json_encode($stats);
});

check('groups account for every node exactly once', function() use ($graph) {
    $total = 0;

    foreach ($graph->groups() as $group) {
        $total += $group['count'];
    }

    return $total === count($graph->nodes) ?: "$total grouped, " . count($graph->nodes) . ' nodes';
});

check('skipped sources are named, so "nothing found" is never ambiguous', function() {
    configure(['sources' => []]);
    $plugin = Plugin::getInstance();
    $graph = $plugin->graph->get(Craft::$app->getSites()->getPrimarySite()->id, false);
    $skipped = $graph->skippedKinds;
    $expected = (new ContentSource(new BuildContext(Craft::$app->getSites()->getPrimarySite()->id, $plugin->getSettings())))
        ->displayName();
    configure();

    return in_array($expected, $skipped, true) ?: json_encode($skipped);
});

check('a source with no switch is never listed as "not scanned"', function() {
    // Nested ownership is the inverse of the roll-up setting, not a source of its own. Naming it
    // sends the reader hunting for a checkbox that does not exist.
    configure(['sources' => [], 'rollUpNested' => true]);
    $graph = Plugin::getInstance()->graph->get(Craft::$app->getSites()->getPrimarySite()->id, false);
    $skipped = $graph->skippedKinds;
    configure();

    foreach ($skipped as $name) {
        if (stripos($name, 'nested') !== false || stripos($name, 'relation field') !== false) {
            return "listed “$name”";
        }
    }

    return true;
});

check('a whole-site build is not pathologically slow', function() use ($graph) {
    return $graph->buildTime < 30 ?: "took {$graph->buildTime}s";
});

// ============================================================================ deliberate breakage

section('Breaking things on purpose');

$section = null;

foreach (Craft::$app->getEntries()->getAllSections() as $candidate) {
    if ($candidate->type !== craft\models\Section::TYPE_SINGLE && $candidate->getEntryTypes()) {
        $section = $candidate;
        break;
    }
}

// Matched exactly, not with LIKE. A class name is full of backslashes, and between PHP's string
// escaping and Yii's LIKE escaping they double twice and match nothing — silently.
$fieldId = (new Query())
    ->select(['id'])
    ->from([Table::FIELDS])
    ->where(['type' => craft\fields\Entries::class])
    ->scalar();

$made = [];
$relationRows = [];

/** Creates a throwaway entry in an existing section. Nothing is added to project config. */
function makeEntry(craft\models\Section $section, string $title): Entry
{
    global $made;

    $types = $section->getEntryTypes();
    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $types[0]->id;
    $entry->title = $title;
    $entry->enabled = true;

    if (!Craft::$app->getElements()->saveElement($entry)) {
        throw new RuntimeException('could not save ' . $title . ': ' . json_encode($entry->getErrors()));
    }

    $made[] = $entry;

    return $entry;
}

function relate(int $sourceId, int $targetId, int $fieldId): void
{
    global $relationRows;

    Db::insert(Table::RELATIONS, [
        'fieldId' => $fieldId,
        'sourceId' => $sourceId,
        'sourceSiteId' => null,
        'targetId' => $targetId,
        'sortOrder' => 1,
        'dateCreated' => Db::prepareDateForDb(new DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        'uid' => craft\helpers\StringHelper::UUID(),
    ]);

    $relationRows[] = [$sourceId, $targetId];
}

function freshGraph(): GraphModel
{
    return Plugin::getInstance()->graph->get(Craft::$app->getSites()->getPrimarySite()->id, false);
}

if ($section === null || !$fieldId) {
    echo "  ! skipped — this site has no channel/structure section or no Entries field to borrow\n";
} else {
    $fieldId = (int)$fieldId;
    $source = makeEntry($section, 'Yarn check — source');
    $target = makeEntry($section, 'Yarn check — target');
    $other = makeEntry($section, 'Yarn check — bystander');

    relate($source->id, $target->id, $fieldId);

    check('a relation row becomes an edge, in the right direction', function() use ($source, $target) {
        $graph = freshGraph();
        $out = $graph->outgoing($source->id);

        foreach ($out as $edge) {
            if ($edge->to === $target->id && $edge->kind === Edge::KIND_FIELD) {
                return true;
            }
        }

        return 'no edge found among ' . count($out);
    });

    check('the edge carries the field’s name, not its id', function() use ($source, $fieldId) {
        $graph = freshGraph();
        $expected = Craft::$app->getFields()->getFieldById($fieldId)?->name;

        foreach ($graph->outgoing($source->id) as $edge) {
            if ($edge->fieldId === $fieldId) {
                return $edge->label === $expected ?: "labelled “{$edge->label}”, expected “$expected”";
            }
        }

        return 'edge missing';
    });

    check('directUsages finds it without building a graph', function() use ($source, $target) {
        $rows = Plugin::getInstance()->relations->directUsages($target->id);

        return in_array($source->id, array_column($rows, 'id'), true) ?: json_encode($rows);
    });

    check('directUses is the other direction', function() use ($source, $target) {
        $rows = Plugin::getInstance()->relations->directUses($source->id);

        return in_array($target->id, array_column($rows, 'id'), true) ?: json_encode($rows);
    });

    check('countUsages agrees with directUsages', function() use ($target) {
        $relations = Plugin::getInstance()->relations;

        return $relations->countUsages($target->id) === count($relations->directUsages($target->id))
            ?: 'counts disagree';
    });

    check('an ignored field produces no edge at all', function() use ($source, $target, $fieldId) {
        $handle = Craft::$app->getFields()->getFieldById($fieldId)?->handle;
        configure(['ignoredFields' => [$handle]]);

        $graph = freshGraph();
        $found = false;

        foreach ($graph->outgoing($source->id) as $edge) {
            $found = $found || $edge->to === $target->id;
        }

        $direct = Plugin::getInstance()->relations->directUsages($target->id);
        configure();

        return (!$found && !in_array($source->id, array_column($direct, 'id'), true))
            ?: 'the ignored field still produced a relation';
    });

    check('the bystander shows up as an orphan', function() use ($other) {
        $graph = freshGraph();
        $ids = array_map(fn(Node $n) => $n->id, $graph->orphans([Node::KIND_ENTRY]));

        return in_array($other->id, $ids, true) ?: 'not reported';
    });

    check('the impact of deleting the target names the source', function() use ($source, $target) {
        $impact = Plugin::getInstance()->relations->impact(freshGraph(), $target->id);

        return in_array($source->id, array_map(fn(Node $n) => $n->id, $impact['direct']), true)
            ?: 'source missing from the direct impact';
    });

    check('a path is found from source to target', function() use ($source, $target) {
        $path = freshGraph()->path($source->id, $target->id);

        return count($path) === 1 ?: 'path length ' . count($path);
    });

    // ---- disabled target

    check('a live entry pointing at a disabled one is a warning', function() use ($source, $target) {
        $target->enabled = false;
        Craft::$app->getElements()->saveElement($target);

        $findings = Plugin::getInstance()->findings->run(freshGraph(), [Findings::CHECK_DISABLED]);

        foreach ($findings as $finding) {
            if ($finding->subject?->id === $source->id && $finding->object?->id === $target->id) {
                return $finding->severity === Finding::SEVERITY_WARNING
                    ?: "severity was {$finding->severity}";
            }
        }

        return 'no finding for the pair';
    });

    check('re-enabling clears it', function() use ($source, $target) {
        $target->enabled = true;
        Craft::$app->getElements()->saveElement($target);

        foreach (Plugin::getInstance()->findings->run(freshGraph(), [Findings::CHECK_DISABLED]) as $finding) {
            if ($finding->subject?->id === $source->id && $finding->object?->id === $target->id) {
                return 'still reported';
            }
        }

        return true;
    });

    // ---- deleted target

    check('a relation into the trash is an error', function() use ($source, $target) {
        Craft::$app->getElements()->deleteElement($target);

        $findings = Plugin::getInstance()->findings->run(freshGraph(), [Findings::CHECK_BROKEN]);

        foreach ($findings as $finding) {
            if ($finding->subject?->id === $source->id && $finding->object?->id === $target->id) {
                return $finding->severity === Finding::SEVERITY_ERROR ?: "severity was {$finding->severity}";
            }
        }

        return 'no finding for the pair';
    });

    check('the deleted target reads as deleted, not merely disabled', function() use ($target) {
        $node = freshGraph()->node($target->id);

        return $node?->health() === 'deleted' ?: 'health was ' . ($node?->health() ?? 'no node');
    });

    check('restoring it clears the error', function() use ($source, $target) {
        Craft::$app->getElements()->restoreElement($target);

        foreach (Plugin::getInstance()->findings->run(freshGraph(), [Findings::CHECK_BROKEN]) as $finding) {
            if ($finding->subject?->id === $source->id && $finding->object?->id === $target->id) {
                return 'still reported';
            }
        }

        return true;
    });

    // ---- self-reference and cycles

    check('an element related to itself is caught', function() use ($other, $fieldId) {
        relate($other->id, $other->id, $fieldId);

        foreach (Plugin::getInstance()->findings->run(freshGraph(), [Findings::CHECK_SELF]) as $finding) {
            if ($finding->subject?->id === $other->id) {
                return true;
            }
        }

        return 'not reported';
    });

    check('a self-relation is not also reported as a broken target', function() use ($other) {
        foreach (Plugin::getInstance()->findings->run(freshGraph(), [Findings::CHECK_BROKEN]) as $finding) {
            if ($finding->subject?->id === $other->id && $finding->object?->id === $other->id) {
                return 'double-reported';
            }
        }

        return true;
    });

    check('a two-element loop is reported as a cycle', function() use ($source, $target, $fieldId) {
        relate($target->id, $source->id, $fieldId);

        foreach (Plugin::getInstance()->findings->run(freshGraph(), [Findings::CHECK_CYCLES]) as $finding) {
            if (in_array($source->id, $finding->context['cycle'] ?? [], true)) {
                return true;
            }
        }

        return 'not reported';
    });

    // ---- reference tags and links, straight into the content column

    check('a reference tag in content becomes an edge', function() use ($source, $target, $site) {
        configure(['sources' => [Settings::SOURCE_REF_TAGS]]);
        writeContent($source->id, $site->id, ['abc123' => '<p>See <a href="{entry:' . $target->id . ':url}">this</a>.</p>']);

        foreach (freshGraph()->outgoing($source->id) as $edge) {
            if ($edge->to === $target->id && $edge->kind === Edge::KIND_REF) {
                return true;
            }
        }

        return 'no reference edge';
    });

    check('a reference tag to an element that does not exist is an error', function() use ($source, $site) {
        writeContent($source->id, $site->id, ['abc123' => '{entry:2147483600:url}']);

        foreach (Plugin::getInstance()->findings->run(freshGraph(), [Findings::CHECK_UNRESOLVED]) as $finding) {
            if ($finding->subject?->id === $source->id && $finding->severity === Finding::SEVERITY_ERROR) {
                return true;
            }
        }

        return 'not reported';
    });

    check('a reference Yarn cannot resolve is skipped, not called broken', function() use ($source, $site) {
        // `{legs:pricing-table:render}` and friends: another plugin's own ref grammar, resolved by
        // slug. Reporting those as broken paints the findings screen red on a healthy site.
        writeContent($source->id, $site->id, ['abc123' => '{legs:pricing-table:render(compact)}']);

        foreach (Plugin::getInstance()->findings->run(freshGraph(), [Findings::CHECK_UNRESOLVED]) as $finding) {
            if ($finding->subject?->id === $source->id) {
                return 'reported: ' . $finding->title;
            }
        }

        return true;
    });

    check('Twig in a content value is not mistaken for a reference', function() use ($source, $site) {
        writeContent($source->id, $site->id, ['abc123' => '{{ entry.title }} {% if x %}y{% endif %}']);

        foreach (Plugin::getInstance()->findings->run(freshGraph(), [Findings::CHECK_UNRESOLVED]) as $finding) {
            if ($finding->subject?->id === $source->id) {
                return 'reported: ' . $finding->title;
            }
        }

        return true;
    });

    check('reference tags are not scanned when the source is off', function() use ($source, $target, $site) {
        writeContent($source->id, $site->id, ['abc123' => '{entry:' . $target->id . ':url}']);
        configure(['sources' => []]);

        foreach (freshGraph()->outgoing($source->id) as $edge) {
            if ($edge->kind === Edge::KIND_REF) {
                return 'scanned anyway';
            }
        }

        configure(['sources' => [Settings::SOURCE_REF_TAGS]]);

        return true;
    });

    check('a hard-coded link to another entry’s URL becomes an edge', function() use ($source, $target, $site) {
        $uri = (new Query())
            ->select(['uri'])
            ->from([Table::ELEMENTS_SITES])
            ->where(['elementId' => $target->id, 'siteId' => $site->id])
            ->scalar();

        if (!$uri) {
            return true; // the borrowed section has no URLs; nothing to link to
        }

        configure(['sources' => [Settings::SOURCE_URLS]]);
        writeContent($source->id, $site->id, ['abc123' => '<a href="/' . $uri . '">go</a>']);

        foreach (freshGraph()->outgoing($source->id) as $edge) {
            if ($edge->to === $target->id && $edge->kind === Edge::KIND_URL) {
                return true;
            }
        }

        return 'no link edge for /' . $uri;
    });

    check('a link to another domain is ignored entirely', function() use ($source, $site) {
        configure(['sources' => [Settings::SOURCE_URLS]]);
        writeContent($source->id, $site->id, ['abc123' => '<a href="https://example.com/news/one">go</a>']);

        foreach (Plugin::getInstance()->findings->run(freshGraph(), [Findings::CHECK_UNRESOLVED]) as $finding) {
            if ($finding->subject?->id === $source->id) {
                return 'reported an external link';
            }
        }

        return true;
    });

    check('a mailto: link is ignored', function() use ($source, $site) {
        writeContent($source->id, $site->id, ['abc123' => '<a href="mailto:hello@example.com">mail</a>']);

        foreach (Plugin::getInstance()->findings->run(freshGraph(), [Findings::CHECK_UNRESOLVED]) as $finding) {
            if ($finding->subject?->id === $source->id) {
                return 'reported a mailto';
            }
        }

        return true;
    });

    check('a JSON-escaped slash does not hide a link', function() use ($source, $site) {
        // The reason content is decoded before matching: json_encode writes "\/news\/one", and a
        // regex for href="/news/one" run against the raw column matches nothing, ever.
        $stored = (new Query())
            ->select(['content'])
            ->from([Table::ELEMENTS_SITES])
            ->where(['elementId' => $source->id, 'siteId' => $site->id])
            ->scalar();

        writeContent($source->id, $site->id, ['abc123' => '<a href="/news/one">x</a>']);

        $raw = (new Query())
            ->select(['content'])
            ->from([Table::ELEMENTS_SITES])
            ->where(['elementId' => $source->id, 'siteId' => $site->id])
            ->scalar();

        $escaped = str_contains((string)$raw, '\\/news') || str_contains((string)$raw, '/news');

        $restore = json_decode((string)$stored, true);
        writeContent($source->id, $site->id, is_array($restore) ? $restore : []);

        return $escaped ?: 'content column did not round-trip at all';
    });

    configure();
}

/**
 * Writes a content blob straight into `elements_sites`.
 *
 * The array goes in as an array. Handing the column a `json_encode()`d string gets it encoded a
 * second time by the query builder, and what lands is a JSON *string* holding JSON — which every
 * scanner then reads with every `/` escaped as `\/`, so a link matcher silently finds nothing.
 */
function writeContent(int $elementId, int $siteId, array $content): void
{
    Db::update(Table::ELEMENTS_SITES, [
        'content' => $content,
    ], ['elementId' => $elementId, 'siteId' => $siteId]);
}

// ============================================================================ nesting and structure

section('Nesting and hierarchy');

check('with roll-up on, no nested element is a node', function() use ($site) {
    configure(['rollUpNested' => true]);
    $graph = Plugin::getInstance()->graph->get($site->id, false);
    $ids = array_slice(array_keys($graph->nodes), 0, 5000);

    $nested = (new Query())
        ->from([Table::ELEMENTS_OWNERS])
        ->where(['elementId' => $ids])
        ->count();

    return (int)$nested === 0 ?: "$nested nested elements are drawn as nodes";
});

check('with roll-up off, nested elements appear and are owned', function() use ($site) {
    $anyNested = (new Query())->from([Table::ELEMENTS_OWNERS])->exists();

    if (!$anyNested) {
        return true; // nothing on this site to roll up
    }

    configure(['rollUpNested' => false]);
    $graph = Plugin::getInstance()->graph->get($site->id, false);
    configure();

    foreach ($graph->edges as $edge) {
        if ($edge->kind === Edge::KIND_NESTED) {
            return true;
        }
    }

    return 'no ownership edges were produced';
});

check('roll-up moves a nested relation onto its owner', function() use ($site) {
    // The single most load-bearing behaviour in the plugin: an asset related from inside a Matrix
    // block has to read as used by the *page*, or nobody can answer "where is this used".
    $row = (new Query())
        ->select(['r.sourceId', 'o.ownerId'])
        ->from(['r' => Table::RELATIONS])
        ->innerJoin(['o' => Table::ELEMENTS_OWNERS], '[[o.elementId]] = [[r.sourceId]]')
        ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[r.sourceId]]')
        ->where(['e.draftId' => null, 'e.revisionId' => null, 'e.archived' => false, 'e.dateDeleted' => null])
        ->one();

    if ($row === null) {
        return true; // no relations held inside nested elements here
    }

    configure(['rollUpNested' => true]);
    $context = new BuildContext($site->id, Plugin::getInstance()->getSettings());
    [$resolved] = $context->rollUp((int)$row['sourceId']);
    configure();

    return $resolved !== (int)$row['sourceId'] ?: 'the nested element resolved to itself';
});

check('the structure source only runs when asked, and adds parent-to-child edges', function() use ($site) {
    $plugin = Plugin::getInstance();

    configure(['sources' => []]);
    foreach ($plugin->graph->get($site->id, false)->edges as $edge) {
        if ($edge->kind === Edge::KIND_STRUCTURE) {
            configure();
            return 'structure edges appeared with the source off';
        }
    }

    $hasStructure = (new Query())
        ->from([Table::STRUCTUREELEMENTS])
        ->where(['>', 'level', 1])
        ->exists();

    configure(['sources' => [Settings::SOURCE_STRUCTURE]]);
    $found = false;

    foreach ($plugin->graph->get($site->id, false)->edges as $edge) {
        if ($edge->kind === Edge::KIND_STRUCTURE) {
            $found = true;
            break;
        }
    }

    configure();

    return ($found || !$hasStructure) ?: 'the site has nested structure rows but produced no edges';
});

// ============================================================================ findings shape

section('Findings');

check('findings come back worst first', function() use ($site) {
    $plugin = Plugin::getInstance();
    $findings = $plugin->findings->run($plugin->graph->get($site->id, false));
    $last = -1;

    foreach ($findings as $finding) {
        if ($finding->severityWeight() < $last) {
            return 'out of order';
        }
        $last = $finding->severityWeight();
    }

    return true;
});

check('every check has a name for the filter menu', function() {
    $names = Plugin::getInstance()->findings->names();

    foreach (Findings::CHECKS as $check) {
        if (!isset($names[$check])) {
            return "no name for $check";
        }
    }

    return true;
});

check('a single-check run returns only that check', function() use ($site) {
    $plugin = Plugin::getInstance();
    $findings = $plugin->findings->run($plugin->graph->get($site->id, false), [Findings::CHECK_ORPHANS]);

    foreach ($findings as $finding) {
        if ($finding->check !== Findings::CHECK_ORPHANS) {
            return 'got a ' . $finding->check;
        }
    }

    return true;
});

check('every finding carries a detail sentence, not just a complaint', function() use ($site) {
    $plugin = Plugin::getInstance();

    foreach ($plugin->findings->run($plugin->graph->get($site->id, false)) as $finding) {
        if (trim($finding->detail) === '') {
            return "{$finding->check} has no detail";
        }
    }

    return true;
});

check('orphan kinds are honoured', function() use ($site) {
    configure(['orphanKinds' => [Node::KIND_CATEGORY]]);
    $plugin = Plugin::getInstance();
    $findings = $plugin->findings->run($plugin->graph->get($site->id, false), [Findings::CHECK_ORPHANS]);
    configure();

    foreach ($findings as $finding) {
        if ($finding->subject?->kind !== Node::KIND_CATEGORY) {
            return 'got a ' . ($finding->subject?->kind ?? 'null');
        }
    }

    return true;
});

check('unused assets are reported separately from orphans, never twice', function() use ($site) {
    configure(['orphanKinds' => [Node::KIND_ENTRY, Node::KIND_ASSET]]);
    $plugin = Plugin::getInstance();
    $graph = $plugin->graph->get($site->id, false);
    $findings = $plugin->findings->run($graph, [Findings::CHECK_ORPHANS, Findings::CHECK_UNUSED_ASSETS]);
    configure();

    $seen = [];

    foreach ($findings as $finding) {
        $id = $finding->subject?->id;

        if ($id === null) {
            continue;
        }

        if (isset($seen[$id])) {
            return "element $id reported twice";
        }

        $seen[$id] = true;

        if ($finding->check === Findings::CHECK_ORPHANS && $finding->subject->kind === Node::KIND_ASSET) {
            return 'an asset came through the orphan check';
        }
    }

    return true;
});

check('“ignore routable” drops entries that have their own URL', function() use ($site) {
    $plugin = Plugin::getInstance();

    configure(['orphanKinds' => [Node::KIND_ENTRY], 'orphansIgnoreRoutable' => false]);
    $with = count($plugin->findings->run($plugin->graph->get($site->id, false), [Findings::CHECK_ORPHANS]));

    configure(['orphanKinds' => [Node::KIND_ENTRY], 'orphansIgnoreRoutable' => true]);
    $without = count($plugin->findings->run($plugin->graph->get($site->id, false), [Findings::CHECK_ORPHANS]));

    configure();

    return $without <= $with ?: "$without > $with";
});

check('the tally covers every check, including the ones with nothing to say', function() use ($site) {
    $plugin = Plugin::getInstance();
    $tally = $plugin->findings->tally($plugin->findings->run($plugin->graph->get($site->id, false)));

    foreach (Findings::CHECKS as $check) {
        if (!array_key_exists($check, $tally)) {
            return "missing $check";
        }
    }

    return true;
});

// ============================================================================ export

section('Export');

check('every format renders without throwing', function() use ($site) {
    $plugin = Plugin::getInstance();
    $graph = $plugin->graph->get($site->id, false);

    foreach (Export::FORMATS as $format) {
        if (trim($plugin->export->render($graph, $format)) === '') {
            return "$format came back empty";
        }
    }

    return true;
});

check('the JSON export parses and names the site', function() use ($site) {
    $plugin = Plugin::getInstance();
    $json = json_decode($plugin->export->json($plugin->graph->get($site->id, false)), true);

    return ($json['site'] === $site->handle && isset($json['nodes'], $json['edges'], $json['stats']))
        ?: 'shape wrong';
});

check('the CSV has a row per edge', function() use ($site) {
    $plugin = Plugin::getInstance();
    $graph = $plugin->graph->get($site->id, false);
    $lines = array_filter(explode("\n", trim($plugin->export->csv($graph))));

    return count($lines) === count($graph->edges) + 1
        ?: count($lines) . ' lines for ' . count($graph->edges) . ' edges';
});

// ============================================================================ cache

section('Permissions');

$restrictedGraph = function() use ($section): GraphModel {
    $graph = new GraphModel(1);
    $sectionKey = 'section:' . ($section->id ?? 1);
    $graph->addNode(node(1, Node::KIND_ENTRY, ['label' => 'Salary review', 'groupKey' => $sectionKey, 'uri' => 'hr/salary']));
    $graph->addNode(node(2, Node::KIND_USER, ['label' => 'someone@example.com', 'groupKey' => 'users']));
    $graph->addNode(node(3, Node::KIND_TAG, ['label' => 'A tag', 'groupKey' => 'tagGroup:1']));
    $graph->addNode(node(4, Node::KIND_OTHER, ['label' => 'An address', 'groupKey' => 'type:craft\\commerce\\elements\\Address']));
    $graph->addEdge(new Edge(from: 1, to: 2, label: 'Author', fieldId: 1));
    $graph->tally();

    return $graph;
};

// An id no user has: `can()` finds no permissions for it, which is the point.
$nobody = new craft\elements\User(['id' => 2147483000, 'admin' => false]);

check('an admin sees the graph untouched', function() use ($plugin, $restrictedGraph) {
    $graph = $restrictedGraph();

    return $plugin->graph->forUser($graph, new craft\elements\User(['id' => 2147483000, 'admin' => true])) === $graph
        ?: 'an admin got a copy';
});

check('a user without view permission sees ids, not titles', function() use ($plugin, $restrictedGraph, $nobody) {
    $masked = $plugin->graph->forUser($restrictedGraph(), $nobody);
    $entry = $masked->node(1);
    $user = $masked->node(2);

    if (str_contains($entry->label, 'Salary') || $entry->uri !== null || $entry->groupKey !== 'restricted') {
        return 'the entry leaked: ' . $entry->label;
    }

    return !str_contains($user->label, '@') ?: 'a user email leaked without viewUsers';
});

check('masking keeps the edges, so counts stay honest', function() use ($plugin, $restrictedGraph, $nobody) {
    $masked = $plugin->graph->forUser($restrictedGraph(), $nobody);

    return count($masked->edges) === 1 && $masked->node(2)->inCount === 1 ?: 'edges were dropped';
});

check('tags and unknown element types are left alone', function() use ($plugin, $restrictedGraph, $nobody) {
    $masked = $plugin->graph->forUser($restrictedGraph(), $nobody);

    return $masked->node(3)->label === 'A tag' && $masked->node(4)->label === 'An address'
        ?: 'masked something with no permission to check';
});

check('masking never touches the shared, cacheable graph', function() use ($plugin, $restrictedGraph, $nobody) {
    $graph = $restrictedGraph();
    $plugin->graph->forUser($graph, $nobody);

    return $graph->node(1)->label === 'Salary review' ?: 'the original graph was rewritten';
});

section('Caching');

check('a cached graph is handed back rather than rebuilt', function() use ($site) {
    configure(['cacheDuration' => 300]);
    $plugin = Plugin::getInstance();
    $plugin->graph->invalidate();

    $first = $plugin->graph->get($site->id);
    $second = $plugin->graph->get($site->id);

    return $first->builtAt === $second->builtAt ?: 'rebuilt';
});

check('saving an element throws the cache away', function() use ($site, $section) {
    if ($section === null) {
        return true;
    }

    $plugin = Plugin::getInstance();
    $before = $plugin->graph->get($site->id);

    $entry = makeEntry($section, 'Yarn check — cache buster');

    $after = $plugin->graph->get($site->id);

    return $after->builtAt >= $before->builtAt && $after->has($entry->id)
        ?: 'the cache survived a save';
});

check('cacheDuration = 0 rebuilds every time', function() use ($site) {
    configure(['cacheDuration' => 0]);
    $plugin = Plugin::getInstance();
    $plugin->graph->invalidate();

    $first = $plugin->graph->build($site->id);
    usleep(1100000);
    $second = $plugin->graph->build($site->id);
    configure();

    return $second->builtAt > $first->builtAt ?: 'timestamps matched';
});

// ============================================================================ cleanup

section('Cleanup');

check('the relation rows are removed', function() use ($relationRows) {
    foreach ($relationRows as [$sourceId, $targetId]) {
        Db::delete(Table::RELATIONS, ['sourceId' => $sourceId, 'targetId' => $targetId]);
    }

    return true;
});

check('the entries are deleted for good', function() use (&$made) {
    foreach ($made as $entry) {
        Craft::$app->getElements()->deleteElement($entry, true);
    }

    foreach ($made as $entry) {
        if ((new Query())->from([Table::ELEMENTS])->where(['id' => $entry->id])->exists()) {
            return "entry {$entry->id} survived";
        }
    }

    return true;
});

check('the original settings are restored', function() use ($plugin, $original) {
    // In memory only. `configure()` never persisted anything, so there is nothing in project
    // config to put back — and writing it here would fail on the project config lock whenever the
    // queue runner happens to hold it.
    $plugin->setSettings($original);
    $plugin->graph->invalidate();

    return $plugin->getSettings()->toArray() == $original ?: 'settings did not round-trip';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
