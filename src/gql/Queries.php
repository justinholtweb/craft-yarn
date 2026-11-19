<?php

namespace justinholtweb\yarn\gql;

use Closure;
use Craft;
use craft\helpers\Gql as GqlHelper;
use craft\models\Site;
use craft\services\Gql;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\Type;
use justinholtweb\yarn\errors\ApiException;
use justinholtweb\yarn\models\Graph as GraphModel;
use justinholtweb\yarn\Plugin;

/**
 * Yarn's GraphQL queries, for schemas with the “Query Yarn” component (`yarn:read`).
 *
 * The answers are {@see \justinholtweb\yarn\services\Api}'s — the same ones the MCP tools give —
 * over a graph masked by {@see \justinholtweb\yarn\services\Graph::forSchema()}. Everything but
 * `yarnUsageCount` reads the whole-site graph, cached the same way the control panel's is, and is
 * priced as CPU-heavy so a schema's complexity limit can keep it in check.
 */
class Queries
{
    public const COMPONENT = 'yarn';

    /** @return array<string, array<string, mixed>> */
    public static function get(): array
    {
        if (!GqlHelper::canSchema(self::COMPONENT)) {
            return [];
        }

        $site = ['type' => Type::string(), 'description' => 'Site handle. Defaults to the primary site, or the first site the schema can read.'];
        $limit = ['type' => Type::int(), 'defaultValue' => 100, 'description' => 'At most 500.'];

        return [
            'yarnElement' => [
                'type' => Types::elementRelations(),
                'description' => 'Everything that points at an element and everything it points at — relation fields, nested blocks rolled up to their page, reference tags and hard-coded links — and what deleting it would break.',
                'args' => [
                    'id' => Type::nonNull(Type::int()),
                    'site' => $site,
                    'depth' => ['type' => Type::int(), 'defaultValue' => 3, 'description' => 'How far to follow indirect delete impact, 1–6.'],
                ],
                'resolve' => fn($root, array $args) => self::answer($args['site'] ?? null, fn(GraphModel $graph, Site $s) => Plugin::getInstance()->api->elementRelations($graph, $s, $args['id'], $args['depth'] ?? 3)),
                'complexity' => Gql::GRAPHQL_COMPLEXITY_CPU_HEAVY,
            ],
            'yarnFindings' => [
                'type' => Types::findings(),
                'description' => 'Problems in the site’s relations, worst first. Leave checks empty for every check.',
                'args' => [
                    'site' => $site,
                    'checks' => ['type' => Type::listOf(Type::nonNull(Type::string())), 'description' => 'brokenTargets, absentTargets, disabledTargets, unresolvedReferences, orphans, unusedAssets, cycles, selfReferences.'],
                    'limit' => $limit,
                ],
                'resolve' => fn($root, array $args) => self::answer($args['site'] ?? null, fn(GraphModel $graph, Site $s) => Plugin::getInstance()->api->findings($graph, $s, $args['checks'] ?? [], $args['limit'] ?? 100)),
                'complexity' => Gql::GRAPHQL_COMPLEXITY_CPU_HEAVY,
            ],
            'yarnUnusedAssets' => [
                'type' => Types::unusedAssets(),
                'description' => 'Assets nothing on the site points at, optionally for one volume. Candidates for cleanup — read the caveat first.',
                'args' => [
                    'site' => $site,
                    'volume' => ['type' => Type::string(), 'description' => 'Volume handle.'],
                    'limit' => $limit,
                ],
                'resolve' => fn($root, array $args) => self::answer($args['site'] ?? null, fn(GraphModel $graph, Site $s) => Plugin::getInstance()->api->unusedAssets($graph, $s, $args['volume'] ?? null, $args['limit'] ?? 100)),
                'complexity' => Gql::GRAPHQL_COMPLEXITY_CPU_HEAVY,
            ],
            'yarnPath' => [
                'type' => Types::path(),
                'description' => 'The shortest chain of relations from one element to another.',
                'args' => [
                    'fromId' => Type::nonNull(Type::int()),
                    'toId' => Type::nonNull(Type::int()),
                    'site' => $site,
                    'direction' => ['type' => Type::string(), 'defaultValue' => GraphModel::DIRECTION_OUT, 'description' => 'out (what each element points at), in, or both.'],
                ],
                'resolve' => fn($root, array $args) => self::answer($args['site'] ?? null, fn(GraphModel $graph, Site $s) => Plugin::getInstance()->api->path($graph, $s, $args['fromId'], $args['toId'], $args['direction'] ?? GraphModel::DIRECTION_OUT)),
                'complexity' => Gql::GRAPHQL_COMPLEXITY_CPU_HEAVY,
            ],
            'yarnUsageCount' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'How many times an element is used — the Relations panel’s count, without building the graph. Cheap enough for every page.',
                'args' => [
                    'id' => Type::nonNull(Type::int()),
                    'site' => $site,
                ],
                'resolve' => function($root, array $args) {
                    try {
                        $site = Plugin::getInstance()->api->siteForSchema($args['site'] ?? null, self::schema());
                    } catch (ApiException $e) {
                        throw new UserError($e->getMessage());
                    }

                    return Plugin::getInstance()->relations->countUsages($args['id'], $site->id);
                },
                'complexity' => Gql::GRAPHQL_COMPLEXITY_QUERY,
            ],
        ];
    }

    /**
     * @param Closure(GraphModel, Site): array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private static function answer(?string $site, Closure $answer): array
    {
        $plugin = Plugin::getInstance();
        $schema = self::schema();

        try {
            $siteModel = $plugin->api->siteForSchema($site, $schema);

            return $answer($plugin->graph->forSchema($plugin->graph->get($siteModel->id), $schema), $siteModel);
        } catch (ApiException $e) {
            throw new UserError($e->getMessage());
        }
    }

    private static function schema(): \craft\models\GqlSchema
    {
        return Craft::$app->getGql()->getActiveSchema();
    }
}
