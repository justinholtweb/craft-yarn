<?php

namespace justinholtweb\yarn\controllers;

use justinholtweb\yarn\models\Edge;
use justinholtweb\yarn\models\Graph as GraphModel;
use justinholtweb\yarn\models\Node;
use justinholtweb\yarn\Plugin;
use yii\web\Response;

/**
 * The three list views: every relation, every asset, every global set.
 *
 * Paginated in PHP rather than in SQL, because the graph these read from is assembled in memory
 * and half of what they show — a reference tag in a rich-text field, the page a Matrix block was
 * rolled up into — does not exist as a row anywhere to page over.
 */
class BrowseController extends BaseController
{
    public const PER_PAGE = 100;

    public function actionIndex(): Response
    {
        $site = $this->site();
        $graph = $this->graph($site);

        $kind = (string)$this->request->getParam('kind', '');
        $group = (string)$this->request->getParam('group', '');
        $search = trim((string)$this->request->getParam('search', ''));
        $page = max(1, (int)$this->request->getParam('page', 1));

        $rows = [];

        foreach ($graph->edges as $edge) {
            $from = $graph->node($edge->from);
            $to = $graph->node($edge->to);

            if ($from === null || $to === null) {
                continue;
            }

            if ($kind !== '' && $edge->kind !== $kind) {
                continue;
            }

            if ($group !== '' && $from->groupKey !== $group && $to->groupKey !== $group) {
                continue;
            }

            if ($search !== '' && !$this->matches($search, $from, $to, $edge)) {
                continue;
            }

            $rows[] = ['edge' => $edge, 'from' => $from, 'to' => $to];
        }

        usort($rows, fn(array $a, array $b) => [$a['from']->group, $a['from']->label, $a['to']->label]
            <=> [$b['from']->group, $b['from']->label, $b['to']->label]);

        $total = count($rows);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        return $this->renderTemplate('yarn/browse/index', [
            'site' => $site,
            'siteOptions' => $this->siteOptions(),
            'graph' => $graph,
            'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'kind' => $kind,
            'group' => $group,
            'search' => $search,
            'kinds' => $this->kindOptions($graph),
            'groups' => $graph->groups(),
        ]);
    }

    public function actionAssets(): Response
    {
        $site = $this->site();
        $graph = $this->graph($site);

        $group = (string)$this->request->getParam('group', '');
        $unusedOnly = (bool)$this->request->getParam('unused');
        $page = max(1, (int)$this->request->getParam('page', 1));

        $rows = Plugin::getInstance()->relations->assetUsage($graph, $group, $unusedOnly);
        $total = count($rows);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $unused = 0;

        foreach ($rows as $row) {
            if ($row['uses'] === 0) {
                $unused++;
            }
        }

        return $this->renderTemplate('yarn/browse/assets', [
            'site' => $site,
            'siteOptions' => $this->siteOptions(),
            'graph' => $graph,
            'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => $total,
            'unused' => $unused,
            'page' => $page,
            'pages' => $pages,
            'group' => $group,
            'unusedOnly' => $unusedOnly,
            'volumes' => array_filter($graph->groups(), fn(array $g) => $g['kind'] === Node::KIND_ASSET),
        ]);
    }

    public function actionGlobals(): Response
    {
        $site = $this->site();
        $graph = $this->graph($site);

        return $this->renderTemplate('yarn/browse/globals', [
            'site' => $site,
            'siteOptions' => $this->siteOptions(),
            'graph' => $graph,
            'rows' => Plugin::getInstance()->relations->globalUsage($graph),
        ]);
    }

    private function matches(string $search, Node $from, Node $to, Edge $edge): bool
    {
        $haystack = mb_strtolower(implode(' ', [
            $from->label, $from->group, $to->label, $to->group, $edge->label,
        ]));

        return str_contains($haystack, mb_strtolower($search));
    }

    /** @return array<int, array{label: string, value: string}> */
    private function kindOptions(GraphModel $graph): array
    {
        $options = [];

        foreach (Edge::KINDS as $kind) {
            $count = $graph->kindCounts[$kind] ?? 0;

            if ($count > 0) {
                $options[] = [
                    'label' => (new Edge(0, 0, $kind))->kindLabel() . " ($count)",
                    'value' => $kind,
                ];
            }
        }

        return $options;
    }
}
