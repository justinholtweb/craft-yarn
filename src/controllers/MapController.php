<?php

namespace justinholtweb\yarn\controllers;

use craft\helpers\UrlHelper;
use justinholtweb\yarn\models\Graph as GraphModel;
use justinholtweb\yarn\Plugin;
use yii\web\Response;

/**
 * The flow chart.
 *
 * The page renders the chrome; the graph itself arrives from {@see self::actionData()} as JSON so
 * a filter change costs one small request rather than a page load.
 */
class MapController extends BaseController
{
    public function actionIndex(): Response
    {
        $site = $this->site();
        $settings = Plugin::getInstance()->getSettings();
        $graph = $this->graph($site);

        return $this->renderTemplate('yarn/map/index', [
            'site' => $site,
            'siteOptions' => $this->siteOptions(),
            'graph' => $graph,
            'stats' => $graph->stats(),
            'groups' => $graph->groups(),
            'maxNodes' => $settings->maxNodes,
            'dataUrl' => UrlHelper::actionUrl('yarn/map/data'),
        ]);
    }

    /**
     * Throws the cached graph away; the page it redirects back to assembles a fresh one.
     *
     * POST with a CSRF token rather than `?rebuild=1`: a rebuild costs seconds of database time on
     * a big site, and a GET link is something any other page can make a signed-in browser fetch.
     */
    public function actionRebuild(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->graph->invalidate();

        return $this->redirectToPostedUrl();
    }

    /**
     * The graph as the browser wants it: flat arrays, short keys, no nulls worth sending.
     */
    public function actionData(): Response
    {
        $this->requireAcceptsJson();

        $site = $this->site();
        $graph = $this->graph($site);
        $settings = Plugin::getInstance()->getSettings();

        $focus = (int)$this->request->getParam('focus', 0);
        $depth = max(1, min(4, (int)$this->request->getParam('depth', 2)));
        $groups = array_filter((array)$this->request->getParam('groups', []));
        $kinds = array_filter((array)$this->request->getParam('kinds', []));
        $hideOrphans = (bool)$this->request->getParam('hideOrphans');
        $limit = $settings->maxNodes;

        $ids = $this->select($graph, $focus, $depth, $groups, $kinds, $hideOrphans);
        $truncated = count($ids) > $limit;

        if ($truncated) {
            // Keep the busiest nodes: a truncated map of the hubs is a map. A truncated map of
            // whichever 400 element ids came back first is a screenful of unrelated dots.
            uasort($ids, fn(int $a, int $b) => $b <=> $a);
            $ids = array_slice($ids, 0, $limit, true);
        }

        $nodes = [];

        foreach (array_keys($ids) as $id) {
            $node = $graph->node($id);

            if ($node !== null) {
                $nodes[] = $node->toArray();
            }
        }

        $edges = [];

        foreach ($graph->edges as $edge) {
            if (isset($ids[$edge->from], $ids[$edge->to]) && $edge->from !== $edge->to) {
                $edges[] = $edge->toArray();
            }
        }

        return $this->asJson([
            'nodes' => $nodes,
            'edges' => $edges,
            'truncated' => $truncated,
            'total' => count($graph->nodes),
            'focus' => $focus ?: null,
        ]);
    }

    /**
     * Which nodes the map should draw, and how interesting each one is.
     *
     * @param string[] $groups
     * @param string[] $kinds
     * @return array<int, int> Element id => weight, used to decide what survives truncation.
     */
    private function select(GraphModel $graph, int $focus, int $depth, array $groups, array $kinds, bool $hideOrphans): array
    {
        $within = $focus > 0 ? $graph->ego($focus, $depth) : null;
        $selected = [];

        foreach ($graph->nodes as $node) {
            if ($within !== null && !isset($within[$node->id])) {
                continue;
            }

            // A focused view ignores the filters below it: you asked for this element's
            // neighbourhood, and hiding half of it would misdescribe the neighbourhood.
            if ($within === null) {
                if ($groups !== [] && !in_array($node->groupKey, $groups, true)) {
                    continue;
                }

                if ($kinds !== [] && !in_array($node->kind, $kinds, true)) {
                    continue;
                }

                if ($hideOrphans && $node->inCount === 0 && $node->outCount === 0) {
                    continue;
                }
            }

            $selected[$node->id] = $node->inCount + $node->outCount;
        }

        return $selected;
    }
}
