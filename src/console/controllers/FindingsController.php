<?php

namespace justinholtweb\yarn\console\controllers;

use craft\helpers\Console;
use justinholtweb\yarn\models\Finding;
use justinholtweb\yarn\Plugin;
use yii\console\ExitCode;

/**
 * `php craft yarn/findings` — the audit, scriptable.
 *
 * Exits non-zero when it finds something at or above `--fail-on`, so it can sit in a deploy
 * pipeline and stop a release that would ship a page pointing into the trash.
 */
class FindingsController extends BaseController
{
    /** Only run these checks, comma-separated. */
    public ?string $only = null;

    /** Exit non-zero at this severity or worse: `error`, `warning`, `notice`, or `never`. */
    public ?string $failOn = 'never';

    /** Most findings to print. */
    public ?string $limit = '50';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['only', 'failOn', 'limit']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['f' => 'failOn']);
    }

    public function actionIndex(): int
    {
        $site = $this->resolveSite();

        if ($site === null) {
            return ExitCode::USAGE;
        }

        $plugin = Plugin::getInstance();
        $graph = $plugin->graph->get($site->id, !$this->fresh);

        $only = $this->only !== null
            ? array_values(array_filter(array_map('trim', explode(',', $this->only))))
            : [];

        $findings = $plugin->findings->run($graph, $only);
        $severities = $plugin->findings->severities($findings);

        $this->stdout("\nYarn findings — {$site->name}\n", Console::BOLD);
        $this->stdout(str_repeat('─', 52) . "\n");

        if ($findings === []) {
            $this->stdout("Nothing to report.\n\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $limit = max(1, (int)$this->limit);

        foreach (array_slice($findings, 0, $limit) as $finding) {
            $colour = match ($finding->severity) {
                Finding::SEVERITY_ERROR => Console::FG_RED,
                Finding::SEVERITY_WARNING => Console::FG_YELLOW,
                default => Console::FG_GREY,
            };

            $this->stdout(str_pad(strtoupper($finding->severity), 9), $colour);
            $this->stdout($finding->title . "\n");

            if ($finding->detail !== '') {
                $this->stdout('         ' . $finding->detail . "\n", Console::FG_GREY);
            }

            if ($finding->subject !== null) {
                $this->stdout('         #' . $finding->subject->id . ' ' . $finding->subject->cpEditUrl() . "\n", Console::FG_GREY);
            }
        }

        if (count($findings) > $limit) {
            $this->stdout("\n… and " . (count($findings) - $limit) . " more.\n", Console::FG_GREY);
        }

        $this->stdout(sprintf(
            "\n%d error, %d warning, %d notice\n\n",
            $severities[Finding::SEVERITY_ERROR],
            $severities[Finding::SEVERITY_WARNING],
            $severities[Finding::SEVERITY_NOTICE],
        ));

        return $this->exitCodeFor($severities);
    }

    /**
     * @param array<string, int> $severities
     */
    private function exitCodeFor(array $severities): int
    {
        // Braced deliberately: `"{$this->failOn}"` rather than `"$this->failOn."`, because a
        // curly quote or a full stop straight after a property name has bitten this codebase
        // before and PHP does not warn.
        $threshold = (string)$this->failOn;

        $triggers = match ($threshold) {
            Finding::SEVERITY_ERROR => [Finding::SEVERITY_ERROR],
            Finding::SEVERITY_WARNING => [Finding::SEVERITY_ERROR, Finding::SEVERITY_WARNING],
            Finding::SEVERITY_NOTICE => [Finding::SEVERITY_ERROR, Finding::SEVERITY_WARNING, Finding::SEVERITY_NOTICE],
            default => [],
        };

        foreach ($triggers as $severity) {
            if (($severities[$severity] ?? 0) > 0) {
                return ExitCode::DATAERR;
            }
        }

        return ExitCode::OK;
    }
}
