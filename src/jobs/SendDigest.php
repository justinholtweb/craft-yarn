<?php

namespace justinholtweb\yarn\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\yarn\Plugin;

/**
 * Sends the findings digest if it is due. Queued by the web fallback trigger.
 *
 * Decides nothing itself: {@see \justinholtweb\yarn\services\Digest::run()} checks the schedule
 * and claims the period, so a job that runs late, twice, or after cron already sent is a no-op.
 */
class SendDigest extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::getInstance()->digest->run();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('yarn', 'Sending the Yarn findings digest');
    }

    public function getTtr(): int
    {
        // A whole-site graph build on a large site, then one email per recipient.
        return 900;
    }
}
