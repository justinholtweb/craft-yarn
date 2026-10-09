<?php
/**
 * "Send a test digest now" over HTTP: POST only, CSRF-checked, behind its own permission — and
 * the settings and findings screens render the digest panel outside every other form.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-yarn/tests/integration/digest-http.php
 *
 * Makes one throwaway non-admin user and deletes it at the end. The successful test send goes to
 * that user's example.test address through the harness's own mailer.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

const BASE = 'http://127.0.0.1/';
const ACTION = 'admin/actions/yarn/digest/send-test';
const PASSWORD = 'Yarn-digest-check-7781';

/** A signed-in client and a CSRF token read from an authenticated page. */
function client(?string $username, ?string $password): array
{
    $http = new Client(['base_uri' => BASE, 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $token = fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');

    if ($username !== null) {
        $login = $http->post('index.php?p=actions/users/login', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $token()],
        ]);

        if ($login->getStatusCode() !== 200) {
            throw new RuntimeException("could not sign in as $username: " . $login->getStatusCode() . ' ' . substr((string)$login->getBody(), 0, 200));
        }
    }

    return [$http, $token()];
}

function postTest(Client $http, ?string $csrf): Psr\Http\Message\ResponseInterface
{
    $params = ['redirect' => Craft::$app->getSecurity()->hashData('yarn/findings')];

    if ($csrf !== null) {
        $params['CRAFT_CSRF_TOKEN'] = $csrf;
    }

    return $http->post(ACTION, ['form_params' => $params]);
}

// A non-admin who can read Yarn but not send digests — yet.
$user = User::find()->username('yarn-digest-tester')->status(null)->one() ?? new User();
$user->username = 'yarn-digest-tester';
$user->email = 'yarn-digest-tester@example.test';
$user->newPassword = PASSWORD;
$user->active = true;
$user->pending = false;
Craft::$app->getElements()->saveElement($user) or exit('could not save the test user: ' . json_encode($user->getErrors()) . "\n");
$permissions = Craft::$app->getUserPermissions();
// Craft gates a plugin's CP section on accessPlugin-<handle>, and Yarn shows only sites the
// user can edit, so a reader needs both.
$editSite = 'editSite:' . Craft::$app->getSites()->getPrimarySite()->uid;
$permissions->saveUserPermissions($user->id, ['accessCp', 'accessPlugin-yarn', $editSite, 'yarn:view']);

echo "\nThe endpoint\n";

check('signed out, a POST is turned away', function() {
    [$http, $csrf] = client(null, null);
    $status = postTest($http, $csrf)->getStatusCode();

    return in_array($status, [302, 403], true) ?: "status $status";
});

check('without the permission, a POST is refused with 403', function() {
    [$http, $csrf] = client('yarn-digest-tester', PASSWORD);
    $status = postTest($http, $csrf)->getStatusCode();

    return $status === 403 ?: "status $status";
});

check('without the permission, the findings screen shows no button', function() {
    [$http] = client('yarn-digest-tester', PASSWORD);
    $res = $http->get('admin/yarn/findings');
    $html = (string)$res->getBody();

    return (str_contains($html, 'yarn-stats') && !str_contains($html, 'yarn/digest/send-test')) ?: 'button shown, or page did not render: ' . $res->getStatusCode() . ' ' . $res->getHeaderLine('Location') . ' ' . substr(trim(strip_tags($html)), 0, 300);
});

$permissions->saveUserPermissions($user->id, ['accessCp', 'accessPlugin-yarn', $editSite, 'yarn:view', 'yarn:sendDigest']);

check('with the permission, a GET is refused — POST only', function() {
    [$http] = client('yarn-digest-tester', PASSWORD);
    $status = $http->get(ACTION)->getStatusCode();

    // 400 or 405 depending on the Craft version; either way not a send.
    return in_array($status, [400, 405], true) ?: "status $status";
});

check('with the permission, a POST without a CSRF token is refused', function() {
    [$http] = client('yarn-digest-tester', PASSWORD);
    $status = postTest($http, null)->getStatusCode();

    return $status === 400 ?: "status $status";
});

check('with the permission, the findings screen shows the button in a form of its own', function() {
    [$http] = client('yarn-digest-tester', PASSWORD);
    $html = (string)$http->get('admin/yarn/findings')->getBody();
    $at = strpos($html, 'yarn/digest/send-test');

    if ($at === false) {
        return 'no button';
    }

    // The nearest <form before the action input is the digest's own, opened after the filter
    // form closed.
    $open = strrpos(substr($html, 0, $at), '<form');
    $close = strrpos(substr($html, 0, $at), '</form>');

    return ($open !== false && $open > (int)$close) ?: 'nested or unopened form';
});

check('with the permission and a token, the test is sent and the user lands back on findings', function() {
    [$http, $csrf] = client('yarn-digest-tester', PASSWORD);
    $response = postTest($http, $csrf);
    $location = $response->getHeaderLine('Location');

    return ($response->getStatusCode() === 302 && str_contains($location, 'yarn/findings')) ?: $response->getStatusCode() . " → $location";
});

check('a second test straight after is held back by the cooldown', function() {
    [$http, $csrf] = client('yarn-digest-tester', PASSWORD);
    postTest($http, $csrf);
    $page = (string)$http->get('admin/yarn/findings')->getBody();

    return str_contains($page, 'a moment ago') ?: 'no cooldown notice';
});

echo "\nThe settings screen\n";

check('it renders the digest section, and the test form sits outside the settings form', function() {
    [$http] = client('admin', 'claudepassword');
    $html = (string)$http->get('admin/yarn/settings')->getBody();
    $settingsForm = strpos($html, 'yarn/settings/save');
    $settingsClose = $settingsForm === false ? false : strpos($html, '</form>', $settingsForm);
    $digestForm = strpos($html, 'yarn/digest/send-test');

    return ($settingsForm !== false && str_contains($html, 'settings[digestRecipients]')
        && str_contains($html, 'settings[digestChecks]') && $digestForm !== false && $digestForm > $settingsClose)
        ?: 'missing or nested';
});

check('cleanup: the test user is deleted', function() use ($user) {
    Craft::$app->getElements()->deleteElement($user, true);

    return true;
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
