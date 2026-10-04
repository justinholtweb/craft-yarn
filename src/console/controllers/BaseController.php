<?php

namespace justinholtweb\yarn\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\models\Site;

/**
 * Shared options and output helpers for Yarn's console commands.
 */
abstract class BaseController extends Controller
{
    public $defaultAction = 'index';

    /**
     * Site handle.
     *
     * Typed as a string on purpose. Yii assigns console options raw when the property's default is
     * null, so a typed `?int` fatals on `--site=default` before the action ever runs.
     */
    public ?string $site = null;

    /** Ignore any cached graph and build a fresh one. */
    public bool $fresh = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['site', 'fresh']);
    }

    protected function resolveSite(): ?Site
    {
        $sites = Craft::$app->getSites();

        if ($this->site === null) {
            return $sites->getPrimarySite();
        }

        $site = $sites->getSiteByHandle($this->site);

        if ($site === null) {
            $this->stderr("No site with the handle “{$this->site}”.\n", Console::FG_RED);
        }

        return $site;
    }

    /**
     * Titles come from whoever wrote them, and a title holding an escape sequence can repaint the
     * terminal or plant a link in it. Control characters other than tab and newline are dropped
     * before Yii adds its own colours.
     */
    public function stdout($string)
    {
        $args = func_get_args();
        $args[0] = self::clean((string)$string);

        return parent::stdout(...$args);
    }

    public function stderr($string)
    {
        $args = func_get_args();
        $args[0] = self::clean((string)$string);

        return parent::stderr(...$args);
    }

    private static function clean(string $value): string
    {
        return (string)preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $value);
    }

    protected function row(string $label, string $value): void
    {
        $this->stdout(str_pad($label, 26));
        $this->stdout("$value\n", Console::FG_CYAN);
    }

    protected function truncate(string $value, int $length = 40): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
