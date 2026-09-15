<?php

namespace justinholtweb\yarn\console\controllers;

use craft\helpers\Console;
use justinholtweb\yarn\Plugin;
use yii\console\ExitCode;

/**
 * `php craft yarn/map` — what the graph looks like, without opening a browser.
 */
class MapController extends BaseController
{
    public function actionIndex(): int
    {
        $site = $this->resolveSite();

        if ($site === null) {
            return ExitCode::USAGE;
        }

        $graph = Plugin::getInstance()->graph->get($site->id, !$this->fresh);
        $stats = $graph->stats();

        $this->stdout("\nYarn — {$site->name}\n", Console::BOLD);
        $this->stdout(str_repeat('─', 52) . "\n");

        $this->row('Nodes', (string)$stats['nodes']);
        $this->row('Relations', (string)$stats['edges']);
        $this->row('Connected', (string)$stats['connected']);
        $this->row('Nothing points at', (string)$stats['unreferenced']);
        $this->row('No relations at all', (string)$stats['isolated']);
        $this->row('Build time', $stats['buildTime'] . 's');

        $this->stdout("\nBy element kind\n", Console::BOLD);

        foreach ($stats['kinds'] as $kind => $count) {
            $this->row("  $kind", (string)$count);
        }

        $this->stdout("\nBy relation kind\n", Console::BOLD);

        if ($stats['edgeKinds'] === []) {
            $this->stdout("  none\n", Console::FG_GREY);
        }

        foreach ($stats['edgeKinds'] as $kind => $count) {
            $this->row("  $kind", (string)$count);
        }

        $hubs = $graph->hubs(10);

        if ($hubs !== []) {
            $this->stdout("\nMost referenced\n", Console::BOLD);

            foreach ($hubs as $node) {
                $this->row('  ' . $this->truncate($node->label), $node->inCount . ' in');
            }
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Throws away every cached graph.
     */
    public function actionFlush(): int
    {
        Plugin::getInstance()->graph->invalidate();
        $this->stdout("Cached graphs discarded.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
