<?php

namespace justinholtweb\yarn\console\controllers;

use craft\helpers\Console;
use craft\helpers\FileHelper;
use justinholtweb\yarn\Plugin;
use justinholtweb\yarn\services\Export;
use yii\console\ExitCode;

/**
 * `php craft yarn/export --format=dot > site.dot`
 */
class ExportController extends BaseController
{
    /** One of `json`, `csv`, `dot`, `mermaid`. */
    public ?string $format = Export::FORMAT_JSON;

    /** Write here instead of to standard output. */
    public ?string $to = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['format', 'to']);
    }

    public function actionIndex(): int
    {
        $site = $this->resolveSite();

        if ($site === null) {
            return ExitCode::USAGE;
        }

        $format = (string)$this->format;

        if (!in_array($format, Export::FORMATS, true)) {
            $this->stderr("Unknown format “{$format}”. One of: " . implode(', ', Export::FORMATS) . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $plugin = Plugin::getInstance();
        $graph = $plugin->graph->get($site->id, !$this->fresh);
        $output = $plugin->export->render($graph, $format);

        if ($this->to === null) {
            // Straight to stdout with no framing, so it can be piped into `dot` or `jq`.
            $this->stdout($output);

            return ExitCode::OK;
        }

        FileHelper::writeToFile($this->to, $output);
        $this->stdout(sprintf("Wrote %d nodes and %d relations to %s\n", count($graph->nodes), count($graph->edges), $this->to), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
