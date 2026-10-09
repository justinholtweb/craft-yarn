<?php

namespace justinholtweb\yarn\controllers;

use Craft;
use justinholtweb\yarn\Plugin;
use Throwable;
use yii\web\Response;

/**
 * "Send a test digest now".
 *
 * POST only, CSRF-checked by Craft, and behind its own permission: it sends email, so it should
 * not be something every user who can read the findings can do on a loop.
 */
class DigestController extends BaseController
{
    /** One test per user per this many seconds. */
    public const TEST_COOLDOWN = 30;

    public function actionSendTest(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DIGEST);

        $plugin = Plugin::getInstance();
        $user = Craft::$app->getUser()->getIdentity();

        // To the digest's own list, so the test shows exactly who the real one reaches. A site
        // that has not set one up yet gets it sent to the person pressing the button.
        $recipients = $plugin->getSettings()->recipientList();

        if ($recipients === [] && $user?->email) {
            $recipients = [$user->email];
        }

        if ($recipients === []) {
            $this->setFailFlash(Craft::t('yarn', 'The digest has no recipients to send a test to.'));

            return $this->redirectToPostedUrl();
        }

        if (!Craft::$app->getCache()->add('yarn:digest:test:' . ($user->id ?? 0), 1, self::TEST_COOLDOWN)) {
            $this->setFailFlash(Craft::t('yarn', 'A test digest was sent a moment ago. Give it half a minute.'));

            return $this->redirectToPostedUrl();
        }

        try {
            $sent = $plugin->digest->sendTest($recipients);
        } catch (Throwable $e) {
            Craft::error('Could not send a test digest: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            $sent = 0;
        }

        if ($sent === 0) {
            $this->setFailFlash(Craft::t('yarn', 'The test digest could not be sent. Check the email settings and storage/logs/yarn.log.'));
        } else {
            $this->setSuccessFlash(Craft::t('yarn', 'Test digest sent to {count, plural, =1{one recipient} other{# recipients}}.', [
                'count' => $sent,
            ]));
        }

        return $this->redirectToPostedUrl();
    }
}
