<?php

namespace justinholtweb\yarn\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use justinholtweb\yarn\models\BuildContext;
use justinholtweb\yarn\models\Edge;
use justinholtweb\yarn\models\Graph as GraphModel;
use justinholtweb\yarn\models\Node;
use justinholtweb\yarn\models\Settings;
use justinholtweb\yarn\Plugin;
use justinholtweb\yarn\sources\ContentSource;
use yii\db\Expression;

/**
 * Relations for one element, from both directions.
 *
 * Two paths in here, deliberately. {@see self::view()} reads the assembled graph and knows about
 * everything — reference tags, hard-coded links, nested blocks. {@see self::directUsages()} goes
 * straight to the relations table for one element.
 *
 * The second exists because the sidebar panel renders on every element edit screen, and building
 * a whole-site graph to answer "is this in use" would put seconds on the load of a page nobody
 * opened Yarn to look at.
 *
 * {@see self::usages()} and {@see self::uses()} add reference tags and hard-coded links to that
 * cheap path (GitHub #2): without them the panel said "pointed at by 0" for a PDF that Yarn's own
 * element screen showed linked from a page. They read only the rows that could possibly mention
 * the element, never the whole site, and are cached until the next element save.
 */
class Relations extends Component
{
    /** @var int[]|null Memoized for the life of the request. */
    private ?array $ignoredFieldIds = null;

    /** The settings fingerprint the memo above was built under. */
    private ?string $ignoredFor = null;

    /**
     * The full picture for one element: what it points at, what points at it, grouped for reading.
     *
     * @return array{
     *     node: Node|null,
     *     outgoing: array<string, array{label: string, kind: string, kindLabel: string, edges: array<int, array{edge: Edge, node: Node}>}>,
     *     incoming: array<string, array{label: string, kind: string, kindLabel: string, edges: array<int, array{edge: Edge, node: Node}>}>,
     *     outCount: int,
     *     inCount: int,
     * }
     */
    public function view(GraphModel $graph, int $elementId): array
    {
        $node = $graph->node($elementId);

        return [
            'node' => $node,
            'outgoing' => $this->group($graph, $graph->outgoing($elementId), 'to'),
            'incoming' => $this->group($graph, $graph->incoming($elementId), 'from'),
            'outCount' => count($graph->outgoing($elementId)),
            'inCount' => count($graph->incoming($elementId)),
        ];
    }

    /**
     * Groups edges by the field they came through, so a page with thirty relations reads as four
     * fields rather than as thirty lines.
     *
     * @param Edge[] $edges
     * @param 'to'|'from' $end Which end of the edge is the *other* element.
     * @return array<string, array{label: string, kind: string, kindLabel: string, edges: array<int, array{edge: Edge, node: Node}>}>
     */
    private function group(GraphModel $graph, array $edges, string $end): array
    {
        $groups = [];

        foreach ($edges as $edge) {
            $other = $graph->node($end === 'to' ? $edge->to : $edge->from);

            if ($other === null) {
                continue;
            }

            $key = $edge->kind . ':' . ($edge->fieldId ?? $edge->label);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'label' => $edge->label !== '' ? $edge->label : $edge->kindLabel(),
                    'kind' => $edge->kind,
                    'kindLabel' => $edge->kindLabel(),
                    'edges' => [],
                ];
            }

            $groups[$key]['edges'][] = ['edge' => $edge, 'node' => $other];
        }

        uasort($groups, fn(array $a, array $b) => [$a['kind'], $a['label']] <=> [$b['kind'], $b['label']]);

        return $groups;
    }

    /**
     * What would be left pointing at nothing if this element went away.
     *
     * @return array{direct: Node[], indirect: array<int, array{node: Node, hops: int}>}
     */
    public function impact(GraphModel $graph, int $elementId, int $depth = 3): array
    {
        $direct = [];

        foreach ($graph->incoming($elementId) as $edge) {
            $node = $graph->node($edge->from);

            if ($node !== null) {
                $direct[$node->id] = $node;
            }
        }

        $indirect = [];

        foreach ($graph->dependents($elementId, $depth) as $id => $hops) {
            if ($hops < 2) {
                continue;
            }

            $node = $graph->node($id);

            if ($node !== null) {
                $indirect[] = ['node' => $node, 'hops' => $hops];
            }
        }

        usort($indirect, fn(array $a, array $b) => [$a['hops'], $a['node']->label] <=> [$b['hops'], $b['node']->label]);

        return ['direct' => array_values($direct), 'indirect' => $indirect];
    }

    // -------------------------------------------------------------------- the cheap single-shot

    /**
     * Everything that points at this element without building the graph: relation fields, plus
     * reference tags and hard-coded links when those sources are switched on.
     *
     * @return array<int, array{id: int, fieldId: int|null, viaId: int|null, kind: string}>
     */
    public function usages(int $elementId, ?int $siteId = null): array
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        return array_merge(
            $this->directUsages($elementId, $siteId),
            $this->contentUsages([$elementId], $siteId)[$elementId] ?? [],
        );
    }

    /**
     * Everything this element points at, the same way.
     *
     * @return array<int, array{id: int, fieldId: int|null, viaId: int|null, kind: string}>
     */
    public function uses(int $elementId, ?int $siteId = null): array
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        return array_merge(
            $this->directUses($elementId, $siteId),
            $this->contentUses($elementId, $siteId),
        );
    }

    /**
     * Elements that point at this one, straight from the relations table.
     *
     * Rolled up the same way the graph rolls up, so an asset used inside a Matrix block is
     * reported as used by the page — which is what the person staring at the delete button needs
     * to know.
     *
     * @return array<int, array{id: int, fieldId: int|null, viaId: int|null, kind: string}>
     */
    public function directUsages(int $elementId, ?int $siteId = null): array
    {
        return $this->directRelations($elementId, $siteId, 'targetId', 'sourceId');
    }

    /**
     * @return array<int, array{id: int, fieldId: int|null, viaId: int|null, kind: string}>
     */
    public function directUses(int $elementId, ?int $siteId = null): array
    {
        return $this->directRelations($elementId, $siteId, 'sourceId', 'targetId');
    }

    /** How many times something uses this element — {@see self::usages()}, counted. */
    public function countUsages(int $elementId, ?int $siteId = null): int
    {
        return count($this->usages($elementId, $siteId));
    }

    /**
     * @return array<int, array{id: int, fieldId: int|null, viaId: int|null, kind: string}>
     */
    private function directRelations(int $elementId, ?int $siteId, string $anchor, string $other): array
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $settings = Plugin::getInstance()->getSettings();

        $rows = (new Query())
            ->select(["r.$other", 'r.fieldId'])
            ->from(['r' => Table::RELATIONS])
            ->innerJoin(['oe' => Table::ELEMENTS], "[[oe.id]] = [[r.$other]]")
            ->where(["r.$anchor" => $elementId])
            ->andWhere([
                'or',
                ['r.sourceSiteId' => null],
                ['r.sourceSiteId' => $siteId],
            ])
            ->andWhere([
                'oe.draftId' => null,
                'oe.revisionId' => null,
                'oe.archived' => false,
                'oe.dateDeleted' => null,
            ])
            ->all();

        if ($rows === []) {
            return [];
        }

        $ignored = array_flip($this->ignoredFieldIds());

        // Only the *source* end is ever nested — a relation targets whatever it targets. So roll
        // up when we are looking at sources, and leave targets alone.
        $rollUp = $anchor === 'targetId' && $settings->rollUpNested;
        $owners = $rollUp
            ? $this->ownersOf(array_map(fn(array $row) => (int)$row[$other], $rows))
            : [];

        $found = [];

        foreach ($rows as $row) {
            $fieldId = $row['fieldId'] !== null ? (int)$row['fieldId'] : null;

            if ($fieldId !== null && isset($ignored[$fieldId])) {
                continue;
            }

            $raw = (int)$row[$other];
            $id = $owners[$raw] ?? $raw;

            $found[$id . ':' . $fieldId] = [
                'id' => $id,
                'fieldId' => $fieldId,
                'viaId' => $id !== $raw ? $raw : null,
                'kind' => Edge::KIND_FIELD,
            ];
        }

        return array_values($found);
    }

    /**
     * "Used by" counts for a page of elements at once — the element index column.
     *
     * The same answer {@see self::countUsages()} gives one element at a time (relation fields,
     * rolled up, distinct per using element and field; plus content references, distinct per
     * using element and kind), in one relations query, one content scan and the owner walk,
     * however many rows the index is showing.
     *
     * @param int[] $ids
     * @return array<int, int> Every id asked about, 0 where nothing uses it.
     */
    public function usageCounts(array $ids, ?int $siteId = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $counts = array_fill_keys($ids, 0);

        if ($ids === []) {
            return $counts;
        }

        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        $query = (new Query())
            ->select(['r.targetId', 'r.sourceId', 'r.fieldId'])
            ->from(['r' => Table::RELATIONS])
            ->innerJoin(['oe' => Table::ELEMENTS], '[[oe.id]] = [[r.sourceId]]')
            ->where(['r.targetId' => $ids])
            ->andWhere(['or', ['r.sourceSiteId' => null], ['r.sourceSiteId' => $siteId]])
            ->andWhere([
                'oe.draftId' => null,
                'oe.revisionId' => null,
                'oe.archived' => false,
                'oe.dateDeleted' => null,
            ]);

        $ignored = $this->ignoredFieldIds();

        if ($ignored !== []) {
            $query->andWhere(['not', ['r.fieldId' => $ignored]]);
        }

        $rows = $query->all();

        foreach ($this->contentUsages($ids, $siteId) as $target => $usages) {
            $counts[$target] += count($usages);
        }

        if ($rows === []) {
            return $counts;
        }

        $owners = Plugin::getInstance()->getSettings()->rollUpNested
            ? $this->ownersOf(array_values(array_unique(array_map(fn(array $row) => (int)$row['sourceId'], $rows))))
            : [];

        $seen = [];

        foreach ($rows as $row) {
            $target = (int)$row['targetId'];
            $source = (int)$row['sourceId'];
            $key = $target . ':' . ($owners[$source] ?? $source) . ':' . $row['fieldId'];

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $counts[$target]++;
            }
        }

        return $counts;
    }

    /**
     * A correlated subquery that finds a row when something uses the element in `$targetColumn` —
     * for `EXISTS` / `NOT EXISTS` in an element query, which is how the "Is used" condition rule
     * filters without building the graph or loading a single element.
     *
     * Relation fields only — unlike the sidebar panel and the index column, which also read
     * reference tags and links. Those can't be expressed as a subquery without matching raw JSON
     * with LIKE and believing every hit, and a filter that calls an asset "used" because its
     * filename appears in a sentence is worse than one that is honest about its scope. Roll-up does not
     * matter here: a nested block that relates to an asset means the asset is used whoever owns
     * the block.
     *
     * @param string $targetColumn The outer query's element id column, e.g. `elements.id`.
     * @param string $siteColumn The outer query's site id column, e.g. `elements_sites.siteId`.
     */
    public function usedSubquery(string $targetColumn = 'elements.id', string $siteColumn = 'elements_sites.siteId'): Query
    {
        $query = (new Query())
            ->select(new Expression('1'))
            ->from(['yarn_r' => Table::RELATIONS])
            ->innerJoin(['yarn_s' => Table::ELEMENTS], '[[yarn_s.id]] = [[yarn_r.sourceId]]')
            ->where("[[yarn_r.targetId]] = [[$targetColumn]]")
            ->andWhere([
                'or',
                ['yarn_r.sourceSiteId' => null],
                "[[yarn_r.sourceSiteId]] = [[$siteColumn]]",
            ])
            ->andWhere([
                'yarn_s.draftId' => null,
                'yarn_s.revisionId' => null,
                'yarn_s.archived' => false,
                'yarn_s.dateDeleted' => null,
            ]);

        $ignored = $this->ignoredFieldIds();

        if ($ignored !== []) {
            $query->andWhere(['not', ['yarn_r.fieldId' => $ignored]]);
        }

        return $query;
    }

    // ------------------------------------------------------- content references, one element at a time

    /**
     * Reference tags and hard-coded links pointing at the given elements.
     *
     * Two steps. A `LIKE` over `elements_sites.content` picks out the rows that could mention a
     * target — its id inside a tag, its UID, its asset filename, the last segment of its URI —
     * and then the very matcher the graph build uses reads those rows and decides. The prefilter
     * is allowed to be loose; the matcher is what makes it right. Cached until the next element
     * save, because the prefilter is a scan of the content column.
     *
     * A link to the homepage has nothing distinctive to search for, so the homepage's incoming
     * links are left to the full graph.
     *
     * @param int[] $ids
     * @return array<int, array<int, array{id: int, fieldId: null, viaId: int|null, kind: string}>> Target id => usages; only targets with some.
     */
    public function contentUsages(array $ids, int $siteId): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $wantsRefs = $settings->uses(Settings::SOURCE_REF_TAGS);
        $wantsUrls = $settings->uses(Settings::SOURCE_URLS);
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ((!$wantsRefs && !$wantsUrls) || $ids === []) {
            return [];
        }

        sort($ids);

        return $this->cached('in', $siteId, $ids, function() use ($ids, $siteId, $settings, $wantsRefs, $wantsUrls) {
            $needles = $this->needles($ids, $siteId, $wantsRefs, $wantsUrls);

            if ($needles === []) {
                return [];
            }

            $column = Craft::$app->getDb()->getIsPgsql() ? 'CAST([[es.content]] AS TEXT)' : 'es.content';
            $query = $this->liveContentRows($siteId)
                ->andWhere(array_merge(['or'], array_map(fn(string $n) => ['like', $column, $n], $needles)));

            $targets = array_flip($ids);
            $found = [];

            $this->scanContent($query, $siteId, $settings, function(Edge $edge) use ($targets, &$found) {
                if (isset($targets[$edge->to])) {
                    $found[$edge->to][$edge->from . ':' . $edge->kind] = [
                        'id' => $edge->from,
                        'fieldId' => null,
                        'viaId' => $edge->viaId,
                        'kind' => $edge->kind,
                    ];
                }
            });

            return array_map('array_values', $found);
        });
    }

    /**
     * Reference tags and hard-coded links in this element's own content — and, with roll-up on,
     * in the content of the blocks it owns, which is where most of a page's links actually live.
     *
     * @return array<int, array{id: int, fieldId: null, viaId: int|null, kind: string}>
     */
    public function contentUses(int $elementId, int $siteId): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->uses(Settings::SOURCE_REF_TAGS) && !$settings->uses(Settings::SOURCE_URLS)) {
            return [];
        }

        return $this->cached('out', $siteId, [$elementId], function() use ($elementId, $siteId, $settings) {
            $ids = [$elementId];

            if ($settings->rollUpNested) {
                $frontier = [$elementId];

                for ($round = 0; $frontier !== [] && $round < 10; $round++) {
                    $frontier = array_values(array_diff(array_map('intval', (new Query())
                        ->select(['eo.elementId'])
                        ->from(['eo' => Table::ELEMENTS_OWNERS])
                        ->innerJoin(['ne' => Table::ELEMENTS], '[[ne.id]] = [[eo.elementId]]')
                        ->where(['eo.ownerId' => $frontier, 'ne.draftId' => null, 'ne.revisionId' => null])
                        ->column()), $ids));
                    $ids = array_merge($ids, $frontier);
                }
            }

            $query = $this->liveContentRows($siteId)->andWhere(['es.elementId' => $ids]);
            $found = [];

            $this->scanContent($query, $siteId, $settings, function(Edge $edge) use ($elementId, &$found) {
                if ($edge->from === $elementId) {
                    $found[$edge->to . ':' . $edge->kind] = [
                        'id' => $edge->to,
                        'fieldId' => null,
                        'viaId' => $edge->viaId,
                        'kind' => $edge->kind,
                    ];
                }
            });

            return array_values($found);
        });
    }

    /**
     * What to search the content column for. Loose on purpose — see {@see self::contentUsages()}.
     *
     * @param int[] $ids
     * @return string[]
     */
    private function needles(array $ids, int $siteId, bool $refs, bool $urls): array
    {
        $needles = [];

        if ($refs) {
            // `{entry:42}`, `{entry:42:url}`, `{entry:42@fr}`, `{entry:42||fallback}`, `{entry:42 || x}`
            foreach ($ids as $id) {
                foreach (['}', ':', '@', '|', ' '] as $after) {
                    $needles[] = ':' . $id . $after;
                }
            }

            foreach ((new Query())->select(['uid'])->from([Table::ELEMENTS])->where(['id' => $ids])->column() as $uid) {
                $needles[] = (string)$uid;
            }
        }

        if ($urls) {
            $uris = (new Query())
                ->select(['uri'])
                ->from([Table::ELEMENTS_SITES])
                ->where(['elementId' => $ids, 'siteId' => $siteId])
                ->andWhere(['not', ['uri' => null]])
                ->column();

            foreach ($uris as $uri) {
                // The last segment, never a slash: a JSON column may spell `/` as `\/`.
                $segment = basename((string)$uri);

                if ($segment !== '' && $uri !== '__home__') {
                    $needles[] = $segment;
                }
            }

            foreach ((new Query())->select(['filename'])->from([Table::ASSETS])->where(['id' => $ids])->column() as $filename) {
                $needles[] = (string)$filename;
            }
        }

        return array_values(array_unique($needles));
    }

    private function liveContentRows(int $siteId): Query
    {
        return (new Query())
            ->select(['es.elementId', 'es.content'])
            ->from(['es' => Table::ELEMENTS_SITES])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[es.elementId]]')
            ->where([
                'es.siteId' => $siteId,
                'e.draftId' => null,
                'e.revisionId' => null,
                'e.archived' => false,
                'e.dateDeleted' => null,
            ])
            ->andWhere(['not', ['es.content' => null]]);
    }

    /**
     * Runs the content matcher over a query's rows, in batches, rolling up only the ids it meets.
     *
     * @param callable(Edge): void $onEdge
     */
    private function scanContent(Query $query, int $siteId, Settings $settings, callable $onEdge): void
    {
        $context = new BuildContext($siteId, $settings, false);
        $source = new ContentSource($context);
        $source->lazyLookups = true;

        foreach ($query->batch($settings->scanBatchSize) as $rows) {
            $context->loadOwnershipFor(array_map(fn(array $row) => (int)$row['elementId'], $rows));

            foreach ($source->scan($rows) as $edge) {
                $onEdge($edge);
            }
        }
    }

    /**
     * Cached on the same terms as the graph: the settings fingerprint and the counter every
     * element save bumps.
     *
     * @template T
     * @param int[] $ids
     * @param callable(): T $compute
     * @return T
     */
    private function cached(string $direction, int $siteId, array $ids, callable $compute): mixed
    {
        $plugin = Plugin::getInstance();
        $duration = $plugin->getSettings()->cacheDuration;

        if ($duration <= 0) {
            return $compute();
        }

        $key = sprintf(
            'yarn:content:%s:%d:%s:%d:%s',
            $direction,
            $siteId,
            $plugin->getSettings()->graphFingerprint(),
            $plugin->graph->stamp(),
            md5(implode(',', $ids)),
        );

        return Craft::$app->getCache()->getOrSet($key, $compute, $duration);
    }

    /**
     * Resolves a handful of element ids to their top-level owners.
     *
     * Deliberately not {@see BuildContext}, which loads the whole of `elements_owners` — right for
     * one whole-site build, ruinous for a sidebar panel on a site where that table has a million
     * rows. This walks up a level at a time, querying only the ids still in play, and on a normal
     * site stops after one or two rounds.
     *
     * @param int[] $ids
     * @return array<int, int> Only the ids that turned out to be nested.
     */
    private function ownersOf(array $ids): array
    {
        $resolved = [];
        /** @var array<int, int> $pending original id => id currently being resolved */
        $pending = array_combine($ids, $ids);
        $rounds = 0;

        while ($pending !== [] && $rounds < 10) {
            $rounds++;

            $owners = (new Query())
                ->select(['elementId', 'ownerId'])
                ->from([Table::ELEMENTS_OWNERS])
                ->where(['elementId' => array_values(array_unique($pending))])
                ->pairs();

            if ($owners === []) {
                break;
            }

            $next = [];

            foreach ($pending as $original => $current) {
                if (!isset($owners[$current])) {
                    continue;
                }

                $owner = (int)$owners[$current];
                $resolved[$original] = $owner;
                $next[$original] = $owner;
            }

            $pending = $next;
        }

        return $resolved;
    }

    /** @return int[] */
    private function ignoredFieldIds(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        // Keyed on the fingerprint rather than memoized outright: settings can change inside one
        // run — the settings screen saving, a test reconfiguring — and a memo that outlives the
        // change goes on filtering by the old list while every other part of Yarn uses the new one.
        $fingerprint = $settings->graphFingerprint();

        if ($this->ignoredFieldIds !== null && $this->ignoredFor === $fingerprint) {
            return $this->ignoredFieldIds;
        }

        $this->ignoredFor = $fingerprint;
        $this->ignoredFieldIds = [];

        foreach ($settings->ignoredFields as $handle) {
            $field = Craft::$app->getFields()->getFieldByHandle($handle);

            if ($field?->id !== null) {
                $this->ignoredFieldIds[] = $field->id;
            }
        }

        return $this->ignoredFieldIds;
    }

    /**
     * Assets grouped by volume, with how often each is used. The delete-with-confidence screen.
     *
     * @return array<int, array{node: Node, uses: int, usedBy: Node[]}>
     */
    public function assetUsage(GraphModel $graph, ?string $groupKey = null, bool $unusedOnly = false): array
    {
        $rows = [];

        foreach ($graph->nodes as $node) {
            if ($node->kind !== Node::KIND_ASSET) {
                continue;
            }

            if ($groupKey !== null && $groupKey !== '' && $node->groupKey !== $groupKey) {
                continue;
            }

            if ($unusedOnly && $node->inCount > 0) {
                continue;
            }

            $usedBy = [];

            foreach ($graph->incoming($node->id) as $edge) {
                $other = $graph->node($edge->from);

                if ($other !== null) {
                    $usedBy[$other->id] = $other;
                }
            }

            $rows[] = ['node' => $node, 'uses' => $node->inCount, 'usedBy' => array_values($usedBy)];
        }

        usort($rows, fn(array $a, array $b) => [$a['uses'], $a['node']->label] <=> [$b['uses'], $b['node']->label]);

        return $rows;
    }

    /**
     * Every global set and what it reaches — one hop, and then everything below.
     *
     * Globals are the blind spot. Nothing in the control panel tells you that the image you are
     * about to delete is the one in the footer of every page on the site.
     *
     * @return array<int, array{node: Node, direct: Node[], reach: int}>
     */
    public function globalUsage(GraphModel $graph, int $depth = 2): array
    {
        $rows = [];

        foreach ($graph->nodes as $node) {
            if ($node->kind !== Node::KIND_GLOBAL) {
                continue;
            }

            $direct = [];

            foreach ($graph->outgoing($node->id) as $edge) {
                $other = $graph->node($edge->to);

                if ($other !== null) {
                    $direct[$other->id] = $other;
                }
            }

            $reach = $graph->ego($node->id, $depth, GraphModel::DIRECTION_OUT);
            unset($reach[$node->id]);

            $rows[] = ['node' => $node, 'direct' => array_values($direct), 'reach' => count($reach)];
        }

        usort($rows, fn(array $a, array $b) => $a['node']->label <=> $b['node']->label);

        return $rows;
    }
}
