<?php

namespace justinholtweb\yarn\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use justinholtweb\yarn\events\BuildGraphEvent;
use justinholtweb\yarn\models\BuildContext;
use justinholtweb\yarn\models\Edge;
use justinholtweb\yarn\models\Graph as GraphModel;
use justinholtweb\yarn\models\Node;
use justinholtweb\yarn\models\Settings;
use justinholtweb\yarn\Plugin;
use justinholtweb\yarn\sources\ContentSource;

/**
 * Assembles the relation graph, and remembers it for as long as it is worth remembering.
 */
class Graph extends Component
{
    /** @see BuildGraphEvent */
    public const EVENT_AFTER_BUILD_GRAPH = 'afterBuildGraph';

    /**
     * Graphs larger than this are not cached.
     *
     * Memcached refuses an item over 1 MB by default and says so only in a log nobody reads;
     * Redis accepts a 60 MB item and then makes every other request wait for it. Rebuilding a
     * graph that big costs seconds — writing it costs the whole site.
     */
    public const CACHE_MAX_BYTES = 4 * 1024 * 1024;

    private const CACHE_STAMP_KEY = 'yarn:stamp';

    /** @var array<string, GraphModel> Per-request memo — one control panel page asks several times. */
    private array $memo = [];

    /**
     * The graph for a site, from cache when there is one.
     */
    public function get(?int $siteId = null, bool $useCache = true): GraphModel
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $key = $this->cacheKey($siteId);

        if ($useCache && isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        if ($useCache && $this->settings()->cacheDuration > 0) {
            $cached = Craft::$app->getCache()->get($key);

            if ($cached instanceof GraphModel) {
                return $this->memo[$key] = $cached;
            }
        }

        $graph = $this->build($siteId);

        $this->memo[$key] = $graph;
        $this->store($key, $graph);

        return $graph;
    }

    /**
     * Builds from scratch, ignoring and not writing the cache.
     */
    public function build(int $siteId): GraphModel
    {
        $started = microtime(true);
        $settings = $this->settings();
        $context = new BuildContext($siteId, $settings);

        $graph = new GraphModel($siteId);

        /** @var array<string, Edge> $edges */
        $edges = [];
        $referenced = [];
        $kindCounts = [];
        $unresolved = [];

        foreach (Plugin::getInstance()->sources->all($context) as $source) {
            if (!$source->isEnabled()) {
                // Only the ones a reader could switch on. "Not scanned: nested" would send
                // somebody hunting for a checkbox that does not exist.
                if ($source->isOptional()) {
                    $graph->skippedKinds[] = $source->displayName();
                }

                continue;
            }

            foreach ($source->collect() as $edge) {
                $key = $edge->key();

                if (isset($edges[$key])) {
                    continue;
                }

                $edges[$key] = $edge;
                $referenced[$edge->from] = true;
                $referenced[$edge->to] = true;
                $kindCounts[$edge->kind] = ($kindCounts[$edge->kind] ?? 0) + 1;
            }

            if ($source instanceof ContentSource) {
                $unresolved = $source->unresolved;
            }
        }

        $graph->kindCounts = $kindCounts;
        $graph->unresolved = $unresolved;

        $nodes = $this->hydrate($siteId, array_keys($referenced), $settings);

        foreach ($nodes as $node) {
            $graph->addNode($node);
        }

        foreach ($edges as $edge) {
            // Both ends have to have survived hydration. An edge into an excluded volume is not a
            // half-edge worth drawing — it is a line running off the page.
            if ($graph->has($edge->from) && $graph->has($edge->to)) {
                $graph->addEdge($edge);
            }
        }

        $graph->tally();
        $graph->builtAt = time();
        $graph->buildTime = round(microtime(true) - $started, 3);

        $this->trigger(self::EVENT_AFTER_BUILD_GRAPH, new BuildGraphEvent(['graph' => $graph]));

        Craft::info(sprintf(
            'Built the graph for site %d: %d nodes, %d edges, %.3Fs',
            $siteId,
            count($graph->nodes),
            count($graph->edges),
            $graph->buildTime,
        ), Plugin::LOG_CATEGORY);

        return $graph;
    }

    /**
     * Throws away every cached graph.
     *
     * A counter in the cache rather than a sweep of keys: there is one graph per site per settings
     * fingerprint, and no cache backend Craft supports can enumerate its own keys by prefix.
     */
    public function invalidate(): void
    {
        $cache = Craft::$app->getCache();
        $cache->set(self::CACHE_STAMP_KEY, (int)$cache->get(self::CACHE_STAMP_KEY) + 1, 0);
        $this->memo = [];
    }

    // ------------------------------------------------------------------------------- hydration

    /**
     * Every node the graph should contain: the site's own content, plus anything an edge reaches
     * that is not part of it — an element from another site, or one in the trash.
     *
     * @param int[] $referenced
     * @return array<int, Node>
     */
    private function hydrate(int $siteId, array $referenced, Settings $settings): array
    {
        $nodes = $this->residentNodes($siteId, $settings);

        $missing = array_values(array_filter($referenced, fn(int $id) => !isset($nodes[$id])));

        if ($missing !== []) {
            foreach (array_chunk($missing, 2000) as $chunk) {
                foreach ($this->nodesByIds($siteId, $chunk) as $node) {
                    $nodes[$node->id] = $node;
                }
            }
        }

        $this->decorate($nodes, $siteId);

        if ($settings->excludedGroups !== []) {
            $excluded = array_flip($settings->excludedGroups);
            $nodes = array_filter($nodes, fn(Node $n) => !isset($excluded[$n->groupKey]));
        }

        if (!$settings->includeDisabled) {
            $nodes = array_filter($nodes, fn(Node $n) => $n->enabled && $n->enabledForSite);
        }

        return $nodes;
    }

    /**
     * @return array<int, Node>
     */
    private function residentNodes(int $siteId, Settings $settings): array
    {
        $query = (new Query())
            ->select([
                'e.id',
                'e.type',
                'e.enabled',
                'e.dateDeleted',
                'es.title',
                'es.uri',
                'siteEnabled' => 'es.enabled',
            ])
            ->from(['e' => Table::ELEMENTS])
            ->innerJoin(['es' => Table::ELEMENTS_SITES], '[[es.elementId]] = [[e.id]]')
            ->where([
                'es.siteId' => $siteId,
                'e.draftId' => null,
                'e.revisionId' => null,
                'e.archived' => false,
                'e.dateDeleted' => null,
            ]);

        if ($settings->rollUpNested) {
            // Nested elements are not nodes when roll-up is on: their relations were reattributed
            // to whatever owns them, so leaving them in would add thousands of dots with nothing
            // attached and an orphan count that is mostly Matrix blocks.
            $query
                ->leftJoin(['o' => Table::ELEMENTS_OWNERS], '[[o.elementId]] = [[e.id]]')
                ->andWhere(['o.elementId' => null]);
        }

        $nodes = [];

        foreach ($query->each(2000) as $row) {
            $node = $this->nodeFromRow($row, true);
            $nodes[$node->id] = $node;
        }

        return $nodes;
    }

    /**
     * @param int[] $ids
     * @return Node[]
     */
    private function nodesByIds(int $siteId, array $ids): array
    {
        $rows = (new Query())
            ->select([
                'e.id',
                'e.type',
                'e.enabled',
                'e.dateDeleted',
                'es.title',
                'es.uri',
                'siteEnabled' => 'es.enabled',
                // Presence of the row is the only reliable "does this element exist in this
                // site" signal. Title and URI are both legitimately null for an asset that does
                // live here, so testing those reports every asset as absent.
                'siteRowId' => 'es.id',
            ])
            ->from(['e' => Table::ELEMENTS])
            ->leftJoin(['es' => Table::ELEMENTS_SITES], ['and', '[[es.elementId]] = [[e.id]]', ['es.siteId' => $siteId]])
            ->where(['e.id' => $ids])
            ->all();

        return array_map(fn(array $row) => $this->nodeFromRow($row, $row['siteRowId'] !== null), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nodeFromRow(array $row, bool $inSite): Node
    {
        $type = (string)$row['type'];

        return new Node(
            id: (int)$row['id'],
            type: $type,
            kind: Node::KINDS_BY_TYPE[$type] ?? Node::KIND_OTHER,
            label: (string)($row['title'] ?? ''),
            uri: $row['uri'] !== null ? (string)$row['uri'] : null,
            enabled: (bool)$row['enabled'],
            enabledForSite: $row['siteEnabled'] === null || (bool)$row['siteEnabled'],
            deleted: $row['dateDeleted'] !== null,
            inSite: $inSite,
        );
    }

    /**
     * Fills in the parts of a node that live on the per-type tables: what it is called when it has
     * no title, and which section, volume or group it belongs to.
     *
     * One query per element type rather than one per element. A join against every type at once
     * would need six left joins on the elements table and return a row of mostly nulls.
     *
     * @param array<int, Node> $nodes
     */
    private function decorate(array &$nodes, int $siteId): void
    {
        $this->decorateEntries($nodes);
        $this->decorateAssets($nodes);
        $this->decorateCategories($nodes);
        $this->decorateTags($nodes);
        $this->decorateUsers($nodes);
        $this->decorateGlobals($nodes, $siteId);

        foreach ($nodes as $node) {
            // Element types Yarn has no special knowledge of — a plugin's own element, or Craft's
            // addresses — still get a group, keyed by class. Without one they collapse into a
            // single unfilterable heap, and on a site with a few thousand addresses that heap is
            // most of the map.
            if ($node->groupKey === '') {
                $node->group = $this->shortType($node->type);
                $node->groupKey = 'type:' . $node->type;
            }

            if ($node->label === '') {
                $node->label = Craft::t('yarn', '{type} #{id}', [
                    'type' => $this->shortType($node->type),
                    'id' => $node->id,
                ]);
            }
        }
    }

    /** @param array<int, Node> $nodes */
    private function decorateEntries(array &$nodes): void
    {
        $sections = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $sections[$section->id] = $section->name;
        }

        $rows = (new Query())
            ->select(['id', 'sectionId', 'status'])
            ->from([Table::ENTRIES])
            ->where(['not', ['sectionId' => null]]);

        foreach ($rows->each(5000) as $row) {
            $node = $nodes[(int)$row['id']] ?? null;

            if ($node === null || $node->kind !== Node::KIND_ENTRY) {
                continue;
            }

            $sectionId = (int)$row['sectionId'];
            $node->group = $sections[$sectionId] ?? Craft::t('yarn', 'Section #{id}', ['id' => $sectionId]);
            $node->groupKey = "section:$sectionId";
            $node->entryStatus = $row['status'] !== null ? (string)$row['status'] : null;
        }

        // Nested entries only reach here with roll-up off, and they have no section at all.
        foreach ($nodes as $node) {
            if ($node->kind === Node::KIND_ENTRY && $node->groupKey === '') {
                $node->group = Craft::t('yarn', 'Nested entries');
                $node->groupKey = 'section:nested';
            }
        }
    }

    /** @param array<int, Node> $nodes */
    private function decorateAssets(array &$nodes): void
    {
        $volumes = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $volumes[$volume->id] = $volume->name;
        }

        $rows = (new Query())
            ->select(['id', 'volumeId', 'filename', 'kind'])
            ->from([Table::ASSETS]);

        foreach ($rows->each(5000) as $row) {
            $node = $nodes[(int)$row['id']] ?? null;

            if ($node === null || $node->kind !== Node::KIND_ASSET) {
                continue;
            }

            // An asset's title is optional and usually unhelpful; the filename is what anyone
            // looking for it in the volume will recognise.
            $node->label = (string)$row['filename'];
            $volumeId = $row['volumeId'] !== null ? (int)$row['volumeId'] : 0;
            $node->group = $volumes[$volumeId] ?? Craft::t('yarn', 'Temporary uploads');
            $node->groupKey = "volume:$volumeId";
        }
    }

    /** @param array<int, Node> $nodes */
    private function decorateCategories(array &$nodes): void
    {
        $groups = [];

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $groups[$group->id] = $group->name;
        }

        foreach ((new Query())->select(['id', 'groupId'])->from([Table::CATEGORIES])->each(5000) as $row) {
            $node = $nodes[(int)$row['id']] ?? null;

            if ($node === null || $node->kind !== Node::KIND_CATEGORY) {
                continue;
            }

            $groupId = (int)$row['groupId'];
            $node->group = $groups[$groupId] ?? Craft::t('yarn', 'Category group #{id}', ['id' => $groupId]);
            $node->groupKey = "categoryGroup:$groupId";
        }
    }

    /** @param array<int, Node> $nodes */
    private function decorateTags(array &$nodes): void
    {
        $groups = [];

        foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
            $groups[$group->id] = $group->name;
        }

        foreach ((new Query())->select(['id', 'groupId'])->from([Table::TAGS])->each(5000) as $row) {
            $node = $nodes[(int)$row['id']] ?? null;

            if ($node === null || $node->kind !== Node::KIND_TAG) {
                continue;
            }

            $groupId = (int)$row['groupId'];
            $node->group = $groups[$groupId] ?? Craft::t('yarn', 'Tag group #{id}', ['id' => $groupId]);
            $node->groupKey = "tagGroup:$groupId";
        }
    }

    /** @param array<int, Node> $nodes */
    private function decorateUsers(array &$nodes): void
    {
        foreach ((new Query())->select(['id', 'username', 'fullName', 'email'])->from([Table::USERS])->each(5000) as $row) {
            $node = $nodes[(int)$row['id']] ?? null;

            if ($node === null || $node->kind !== Node::KIND_USER) {
                continue;
            }

            $node->label = (string)($row['fullName'] ?: $row['username'] ?: $row['email']);
            $node->group = Craft::t('yarn', 'Users');
            $node->groupKey = 'users';
        }
    }

    /**
     * Global sets are elements like any other, and they relate like any other — which is why
     * "where is this image used?" so often comes back empty until someone remembers the footer.
     *
     * @param array<int, Node> $nodes
     */
    private function decorateGlobals(array &$nodes, int $siteId): void
    {
        foreach ((new Query())->select(['id', 'name'])->from([Table::GLOBALSETS])->all() as $row) {
            $node = $nodes[(int)$row['id']] ?? null;

            if ($node === null || $node->kind !== Node::KIND_GLOBAL) {
                continue;
            }

            $node->label = (string)$row['name'];
            $node->group = Craft::t('yarn', 'Globals');
            $node->groupKey = 'globals';
        }
    }

    private function shortType(string $class): string
    {
        $parts = explode('\\', $class);

        return (string)end($parts);
    }

    // ----------------------------------------------------------------------------------- cache

    private function cacheKey(int $siteId): string
    {
        $stamp = (int)Craft::$app->getCache()->get(self::CACHE_STAMP_KEY);

        return sprintf('yarn:graph:%d:%s:%d', $siteId, $this->settings()->graphFingerprint(), $stamp);
    }

    private function store(string $key, GraphModel $graph): void
    {
        $duration = $this->settings()->cacheDuration;

        if ($duration <= 0) {
            return;
        }

        $payload = serialize($graph);

        if (strlen($payload) > self::CACHE_MAX_BYTES) {
            Craft::info(sprintf(
                'Not caching the graph for site %d: %s exceeds the %s ceiling.',
                $graph->siteId,
                Craft::$app->getFormatter()->asShortSize(strlen($payload)),
                Craft::$app->getFormatter()->asShortSize(self::CACHE_MAX_BYTES),
            ), Plugin::LOG_CATEGORY);

            return;
        }

        Craft::$app->getCache()->set($key, $graph, $duration);
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
