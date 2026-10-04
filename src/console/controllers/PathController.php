<?php

namespace justinholtweb\yarn\console\controllers;

use craft\helpers\Console;
use justinholtweb\yarn\models\Graph as GraphModel;
use justinholtweb\yarn\Plugin;
use yii\console\ExitCode;

/**
 * `php craft yarn/path 1234 5678` — how one element reaches another.
 *
 * The answer to "why is this PDF on the homepage": a chain of relations, each named, rather than
 * a list of direct relations that does not contain the answer.
 */
class PathController extends BaseController
{
    /** Follow relations in both directions, not only the way they point. */
    public bool $undirected = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['undirected']);
    }

    public function actionIndex(int $from, int $to): int
    {
        $site = $this->resolveSite();

        if ($site === null) {
            return ExitCode::USAGE;
        }

        $graph = Plugin::getInstance()->graph->get($site->id, !$this->fresh);

        $direction = $this->undirected
            ? GraphModel::DIRECTION_BOTH
            : GraphModel::DIRECTION_OUT;

        $path = $graph->path($from, $to, $direction);

        if ($path === []) {
            $this->stdout("\nNo path from #$from to #$to"
                . ($this->undirected ? ".\n\n" : " following relations forwards. Try --undirected.\n\n"), Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout("\n");
        $current = $from;

        foreach ($path as $edge) {
            $next = $edge->from === $current ? $edge->to : $edge->from;
            $fromNode = $graph->node($current);
            $toNode = $graph->node($next);

            $this->stdout('  ' . ($fromNode->label ?? "#$current"), Console::FG_CYAN);
            $this->stdout('  —' . ($edge->label !== '' ? " {$edge->label} " : ' ') . '→  ');
            $this->stdout(($toNode->label ?? "#$next") . "\n", Console::FG_CYAN);

            $current = $next;
        }

        $this->stdout("\n" . count($path) . " hop(s).\n\n", Console::FG_GREY);

        return ExitCode::OK;
    }
}
