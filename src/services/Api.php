<?php

namespace justinholtweb\yarn\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\models\GqlSchema;
use craft\models\Site;
use justinholtweb\yarn\errors\ApiException;
use justinholtweb\yarn\models\Edge;
use justinholtweb\yarn\models\Finding;
use justinholtweb\yarn\models\Graph as GraphModel;
use justinholtweb\yarn\models\Node;
use justinholtweb\yarn\Plugin;

/**
 * The answers Yarn gives to machines — the MCP tools and the GraphQL queries — in one place, so
 * the two can never disagree about what "used by" means.
 *
 * Plain arrays out, keyed the same way for both: GraphQL's default resolver reads array keys, and
 * an MCP tool result is JSON anyway. Callers pass a graph that is already masked for whoever is
 * asking ({@see Graph::forUser()}, {@see Graph::forSchema()}); nothing in here checks access.
 */
class Api extends Component
{
    /** Most rows any one answer returns, whatever it is asked for. */
    public const MAX_LIMIT = 500;

    public const CAVEAT = 'Yarn can only see what it scans: relation fields, plus reference tags and hard-coded links when those sources are on. Something used only from a Twig template, a front-end JS bundle or another site entirely still reads as unused.';

    // ---------------------------------------------------------------------------------- answers

    /** @return array<string, mixed> */
    public function elementRelations(GraphModel $graph, Site $site, int $elementId, int $depth = 3): array
    {
        $node = $graph->node($elementId);

        if ($node === null) {
            throw new ApiException("Element #$elementId is not in Yarn's graph for the {$site->handle} site.");
        }

        $relations = Plugin::getInstance()->relations;
        $view = $relations->view($graph, $elementId);
        $impact = $relations->impact($graph, $elementId, max(1, min($depth, 6)));

        return [
            'site' => $site->handle,
            'element' => $node->toArray(),
            'pointedAtBy' => $this->groups($view['incoming']),
            'pointsAt' => $this->groups($view['outgoing']),
            'pointedAtByCount' => $view['inCount'],
            'pointsAtCount' => $view['outCount'],
            'deleteImpact' => [
                'direct' => array_map(fn(Node $n) => $n->toArray(), $impact['direct']),
                'indirect' => array_map(fn(array $row) => ['hops' => $row['hops'], 'node' => $row['node']->toArray()], $impact['indirect']),
            ],
            'caveat' => self::CAVEAT,
        ];
    }

    /**
     * @param string[] $checks Empty for every check.
     * @return array<string, mixed>
     */
    public function findings(GraphModel $graph, Site $site, array $checks = [], int $limit = 100): array
    {
        $service = Plugin::getInstance()->findings;
        $names = $service->names();
        $unknown = array_diff($checks, array_keys($names));

        if ($unknown !== []) {
            throw new ApiException('Unknown check(s): ' . implode(', ', $unknown) . '. Known: ' . implode(', ', array_keys($names)) . '.');
        }

        $all = $service->run($graph, array_values($checks));
        $limit = $this->limit($limit);
        $tally = [];

        foreach ($service->tally($all) as $check => $count) {
            $tally[] = ['check' => $check, 'name' => $names[$check] ?? $check, 'count' => $count];
        }

        return [
            'site' => $site->handle,
            'total' => count($all),
            'tally' => $tally,
            'findings' => array_map(fn(Finding $f) => $f->toArray(), array_slice($all, 0, $limit)),
            'truncated' => count($all) > $limit,
            'caveat' => self::CAVEAT,
        ];
    }

    /** @return array<string, mixed> */
    public function unusedAssets(GraphModel $graph, Site $site, ?string $volume = null, int $limit = 100): array
    {
        $groupKey = null;

        if ($volume !== null && $volume !== '') {
            $model = Craft::$app->getVolumes()->getVolumeByHandle($volume);

            if ($model === null) {
                throw new ApiException("No volume with the handle \"$volume\".");
            }

            $groupKey = 'volume:' . $model->id;
        }

        $rows = Plugin::getInstance()->relations->assetUsage($graph, $groupKey, true);
        $limit = $this->limit($limit);

        return [
            'site' => $site->handle,
            'total' => count($rows),
            'assets' => array_map(fn(array $row) => $row['node']->toArray(), array_slice($rows, 0, $limit)),
            'truncated' => count($rows) > $limit,
            'caveat' => self::CAVEAT,
        ];
    }

    /** @return array<string, mixed> */
    public function path(GraphModel $graph, Site $site, int $fromId, int $toId, string $direction = GraphModel::DIRECTION_OUT): array
    {
        if (!in_array($direction, [GraphModel::DIRECTION_OUT, GraphModel::DIRECTION_IN, GraphModel::DIRECTION_BOTH], true)) {
            throw new ApiException('direction must be one of: out, in, both.');
        }

        foreach ([$fromId, $toId] as $id) {
            if (!$graph->has($id)) {
                throw new ApiException("Element #$id is not in Yarn's graph for the {$site->handle} site.");
            }
        }

        $steps = [];

        foreach ($graph->path($fromId, $toId, $direction) as $edge) {
            $steps[] = $this->edge($graph, $edge);
        }

        return [
            'site' => $site->handle,
            'found' => $steps !== [],
            'hops' => count($steps),
            'steps' => $steps,
        ];
    }

    // ------------------------------------------------------------------------------------ sites

    /**
     * The site a user asked about — by handle, or the primary site — held to the control panel's
     * rule: a site you can't edit is a site you can't map.
     */
    public function siteForUser(?string $handle, User $user): Site
    {
        $site = $this->site($handle);

        // Craft's own rule from Sites::getEditableSiteIds(), applied to this user rather than to
        // whoever the request's identity happens to be.
        if (!$user->admin && Craft::$app->getIsMultiSite() && !$user->can("editSite:$site->uid")) {
            throw new ApiException("You don't have access to the {$site->handle} site.");
        }

        return $site;
    }

    /**
     * The site a GraphQL schema asked about. Without a handle: the primary site if the schema can
     * read it, otherwise the first site it can.
     */
    public function siteForSchema(?string $handle, GqlSchema $schema): Site
    {
        $user = $schema->user ?? null;

        if ($user instanceof User) {
            return $this->siteForUser($handle, $user);
        }

        $readable = array_flip(array_filter((array)($schema->getAllScopePairsForAction('read')['sites'] ?? []), 'is_string'));
        $can = fn(Site $site) => isset($readable[$site->uid]);

        if ($handle !== null && $handle !== '') {
            $site = $this->site($handle);

            if (!$can($site)) {
                throw new ApiException("This schema can't query the {$site->handle} site.");
            }

            return $site;
        }

        $sites = Craft::$app->getSites();

        foreach ([$sites->getPrimarySite(), ...$sites->getAllSites()] as $site) {
            if ($can($site)) {
                return $site;
            }
        }

        throw new ApiException('This schema can\'t query any site.');
    }

    private function site(?string $handle): Site
    {
        $sites = Craft::$app->getSites();

        if ($handle === null || $handle === '') {
            return $sites->getPrimarySite();
        }

        return $sites->getSiteByHandle($handle) ?? throw new ApiException("No site with the handle \"$handle\".");
    }

    // ---------------------------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function edge(GraphModel $graph, Edge $edge): array
    {
        return $edge->toArray() + [
            'kindLabel' => $edge->kindLabel(),
            'fromLabel' => $graph->node($edge->from)?->label,
            'toLabel' => $graph->node($edge->to)?->label,
        ];
    }

    /**
     * @param array<string, array{label: string, kind: string, kindLabel: string, edges: array<int, array{edge: Edge, node: Node}>}> $groups
     * @return list<array<string, mixed>>
     */
    private function groups(array $groups): array
    {
        $out = [];

        foreach ($groups as $group) {
            $out[] = [
                'field' => $group['label'],
                'kind' => $group['kind'],
                'kindLabel' => $group['kindLabel'],
                'elements' => array_map(fn(array $pair) => [
                    'node' => $pair['node']->toArray(),
                    'via' => $pair['edge']->via,
                ], $group['edges']),
            ];
        }

        return $out;
    }

    private function limit(int $limit): int
    {
        return max(1, min($limit, self::MAX_LIMIT));
    }
}
