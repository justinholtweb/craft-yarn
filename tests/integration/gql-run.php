<?php
/**
 * Helper for gql.php, not a suite: runs ONE GraphQL query in a fresh process and prints the result
 * as JSON.
 *
 * Craft builds one schema per request, and some of its argument types outlive
 * `Gql::flushCaches()`, so a second schema in the same process fails with "multiple types named
 * QueryArgument". A process per query is what a real request is anyway.
 *
 * Reads {"scope": [...], "query": "...", "variables": {...}, "settings": {...}} on stdin.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$payload = json_decode(stream_get_contents(STDIN), true);
$plugin = justinholtweb\yarn\Plugin::getInstance();

if (!empty($payload['settings'])) {
    $settings = new justinholtweb\yarn\models\Settings();
    $settings->setAttributes(array_merge($plugin->getSettings()->toArray(), $payload['settings']), false);
    $plugin->setSettings($settings->toArray());
}

$schema = new craft\models\GqlSchema([
    'name' => 'Yarn test',
    'uid' => craft\helpers\StringHelper::UUID(),
    'scope' => $payload['scope'],
]);

Craft::$app->getGql()->invalidateCaches();

echo json_encode(Craft::$app->getGql()->executeQuery($schema, $payload['query'], $payload['variables'] ?: null, null, true));
