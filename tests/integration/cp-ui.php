<?php
/**
 * Yarn's control panel screens, rendered over HTTP in the plugin-testing harness and checked
 * against Craft's own conventions.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-yarn/tests/integration/cp-ui.php
 *
 * Until 5.0.1 (GitHub #1): the map's "Show only" filter was a bare <select multiple> — "0 selected"
 * in Chrome, a two-row scrolling list in Firefox; the legend floated over the drawing and hid the
 * nodes under it; filter selects elsewhere were unstyled native controls; the stylesheet carried its
 * own colours and a dark-mode block that turned the legend dark on a light control panel; and
 * templates were full of inline styles. The map's framing is covered by tests/js/map.test.mjs.
 *
 * Read-only: signs in as the harness admin and changes nothing.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

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

/** The part of a CP page Yarn renders: from its `.yarn` wrapper to the end of the content block. */
function yarnContent(string $html): string
{
    $start = strpos($html, '<div class="yarn');

    return $start === false ? '' : substr($html, $start, (strpos($html, '</main>', $start) ?: strlen($html)) - $start);
}

$plugin = dirname(__DIR__, 2);
$http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false]);
$csrf = (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');
$http->post('index.php?p=actions/users/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['loginName' => 'admin', 'password' => 'claudepassword', 'CRAFT_CSRF_TOKEN' => $csrf]])->getStatusCode() === 200
    or exit("Could not sign in as the harness admin.\n");

$elementId = (int)(new craft\db\Query())->select('r.sourceId')->from('{{%relations}} r')
    ->innerJoin('{{%elements}} e', '[[e.id]] = [[r.sourceId]]')
    ->where(['e.draftId' => null, 'e.revisionId' => null, 'e.dateDeleted' => null])->scalar();

$screens = [
    'map' => 'yarn',
    'browse' => 'yarn/browse',
    'asset usage' => 'yarn/assets',
    'findings' => 'yarn/findings',
    'globals' => 'yarn/globals',
    'settings' => 'yarn/settings',
    'element' => "yarn/element/$elementId",
];

$pages = [];
foreach ($screens as $name => $path) {
    $response = $http->get("index.php?p=admin/$path");
    $pages[$name] = ['status' => $response->getStatusCode(), 'html' => (string)$response->getBody()];
}

echo "\nEvery screen\n";

foreach ($pages as $name => $page) {
    check("$name renders", fn() => $page['status'] === 200 && yarnContent($page['html']) !== '' ?: "status {$page['status']}");
}

check('no inline styles in Yarn’s markup (Craft’s selectize hides its own <select>, which is fine)', function() use ($pages) {
    $found = [];
    foreach ($pages as $name => $page) {
        preg_match_all('/\sstyle="([^"]*)"/', yarnContent($page['html']), $m);
        foreach ($m[1] as $style) {
            if (trim($style) !== 'display: none;' && trim($style) !== 'display: none') {
                $found[] = "$name: $style";
            }
        }
    }

    return $found === [] ?: implode('; ', array_unique($found));
});

check('every <select> is one of Craft’s (inside .select or .selectize), not a bare native control', function() use ($pages) {
    $found = [];
    foreach ($pages as $name => $page) {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . yarnContent($page['html']));
        foreach ($doc->getElementsByTagName('select') as $select) {
            $wrapper = $select->parentNode instanceof DOMElement ? $select->parentNode->getAttribute('class') : '';
            if (!preg_match('/\b(select|selectize)\b/', $wrapper)) {
                $found[] = "$name #" . $select->getAttribute('id');
            }
        }
    }

    return $found === [] ?: implode(', ', $found);
});

echo "\nThe map\n";

$map = yarnContent($pages['map']['html']);

check('“Show only” is Craft’s multi-select, not a bare <select multiple>', function() use ($map) {
    return (bool)preg_match('/<div class="[^"]*\bselectize\b[^"]*">\s*<select[^>]*id="yarn-groups"[^>]*multiple/', $map)
        || (bool)preg_match('/<div class="[^"]*\bselectize\b[^"]*">\s*<select[^>]*multiple[^>]*id="yarn-groups"/', $map)
        ?: 'not wrapped in .selectize';
});

check('the legend and status sit in a bar above the drawing, not over it', function() use ($map) {
    $bar = strpos($map, 'yarn-map-bar');
    $legend = strpos($map, 'data-yarn-legend');
    $status = strpos($map, 'data-yarn-status');
    $canvas = strpos($map, 'yarn-map-canvas');

    return !str_contains($map, 'yarn-map-overlay') && $bar !== false && $canvas !== false && $bar < $legend && $legend < $canvas && $status < $canvas
        ?: 'legend or status is laid over the canvas';
});

check('the checkbox label isn’t styled as a field heading', function() use ($map) {
    return !preg_match('/<label[^>]*class="[^"]*yarn-toolbar-label[^"]*"[^>]*for="yarn-hide-orphans"/', $map)
        && str_contains($map, 'id="yarn-hide-orphans"') ?: 'heading class on the checkbox label';
});

echo "\nThe stylesheet\n";

$css = file_get_contents("$plugin/src/web/assets/cp/dist/yarn-cp.css");

check('uses Craft’s colour variables, not its own hex or rgba values', function() use ($css) {
    preg_match_all('/#[0-9a-f]{3,8}\b|rgba?\(/i', $css, $m);

    return $m[0] === [] ?: implode(', ', array_unique($m[0]));
});

check('has no dark-mode block (the control panel has no dark mode, so it only ever mismatched)', fn() => !str_contains($css, 'prefers-color-scheme') ?: 'found');

check('headings are a class, not every label in the toolbar (which caught checkbox labels)', fn() => !preg_match('/\.yarn-toolbar label\s*\{/', $css) ?: 'found `.yarn-toolbar label`');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
