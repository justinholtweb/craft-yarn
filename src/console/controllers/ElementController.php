<?php

namespace justinholtweb\yarn\console\controllers;

use craft\helpers\Console;
use justinholtweb\yarn\Plugin;
use yii\console\ExitCode;

/**
 * `php craft yarn/element 1234` — both directions for one element, in a terminal.
 *
 * The command to run before deleting something on a server where opening the control panel means
 * finding somebody's password.
 */
class ElementController extends BaseController
{
    /** How many hops of knock-on impact to report. */
    public ?string $depth = '2';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['depth']);
    }

    public function actionIndex(int $elementId): int
    {
        $site = $this->resolveSite();

        if ($site === null) {
            return ExitCode::USAGE;
        }

        $plugin = Plugin::getInstance();
        $graph = $plugin->graph->get($site->id, !$this->fresh);
        $view = $plugin->relations->view($graph, $elementId);

        if ($view['node'] === null) {
            $this->stderr("Element #$elementId is not in this site's graph.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $node = $view['node'];

        $this->stdout("\n{$node->label}\n", Console::BOLD);
        $this->stdout("#{$node->id} · {$node->kind}" . ($node->group !== '' ? " · {$node->group}" : '')
            . " · {$node->health()}\n", Console::FG_GREY);

        $this->section('Points at (' . $view['outCount'] . ')', $view['outgoing'], 'to');
        $this->section('Pointed at by (' . $view['inCount'] . ')', $view['incoming'], 'from');

        $impact = $plugin->relations->impact($graph, $elementId, max(1, (int)$this->depth));

        if ($impact['indirect'] !== []) {
            $this->stdout("\nKnock-on if deleted\n", Console::BOLD);

            foreach (array_slice($impact['indirect'], 0, 20) as $row) {
                $this->stdout(sprintf("  %s  %s\n", str_pad($row['hops'] . ' hops', 9), $this->truncate($row['node']->label, 60)));
            }

            if (count($impact['indirect']) > 20) {
                $this->stdout('  … and ' . (count($impact['indirect']) - 20) . " more\n", Console::FG_GREY);
            }
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * @param array<string, array{label: string, kind: string, edges: array<int, array{edge: mixed, node: mixed}>}> $groups
     */
    private function section(string $title, array $groups, string $end): void
    {
        $this->stdout("\n$title\n", Console::BOLD);

        if ($groups === []) {
            $this->stdout("  nothing\n", Console::FG_GREY);

            return;
        }

        foreach ($groups as $group) {
            $this->stdout('  ' . $group['label'] . "\n", Console::FG_YELLOW);

            foreach ($group['edges'] as $row) {
                $other = $row['node'];
                $via = $row['edge']->via !== null ? ' (via ' . $row['edge']->via . ')' : '';
                $health = $other->health() === 'ok' ? '' : ' [' . $other->health() . ']';

                $this->stdout(sprintf(
                    "    #%-8d %s%s%s\n",
                    $other->id,
                    $this->truncate($other->label, 52),
                    $via,
                    $health,
                ));
            }
        }
    }
}
