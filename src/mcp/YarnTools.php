<?php

namespace justinholtweb\yarn\mcp;

use Closure;
use Craft;
use craft\elements\User;
use craft\mcp\attributes\RequiresPermission;
use justinholtweb\yarn\errors\ApiException;
use justinholtweb\yarn\models\Graph as GraphModel;
use justinholtweb\yarn\Plugin;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Yarn's tools on Craft's own MCP server (Craft 5.12+).
 *
 * Registered through `craft\services\Mcp::EVENT_REGISTER_CAPABILITY_TYPES`, and only when that
 * service exists — on 5.3–5.11 this class is never loaded, so the attribute classes it names
 * never need to resolve.
 *
 * Every tool is read-only and needs the same permission as the Yarn screens. The answers come from
 * {@see \justinholtweb\yarn\services\Api}, which the GraphQL queries share, over a graph masked by
 * {@see \justinholtweb\yarn\services\Graph::forUser()} exactly as the control panel masks it.
 */
class YarnTools
{
    /** @return array<string, mixed> */
    #[McpTool(
        name: 'yarn_element_relations',
        description: 'Everything that points at a Craft element and everything it points at — relation fields, Matrix/nested blocks rolled up to their page, reference tags and hard-coded links — plus what would be left pointing at nothing if it were deleted. Use before deleting an asset or entry.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission(Plugin::PERMISSION_VIEW)]
    public function elementRelations(int $elementId, ?string $site = null, int $depth = 3): array
    {
        return $this->answer($site, fn(GraphModel $graph, $siteModel) => Plugin::getInstance()->api->elementRelations($graph, $siteModel, $elementId, $depth));
    }

    /**
     * @param string[] $checks
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'yarn_findings',
        description: 'Problems in the site\'s relations: broken and trashed targets, disabled targets, unresolved reference tags and links, orphans, unused assets, cycles and self-references. Each finding says what to do about it. Leave checks empty for every check.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission(Plugin::PERMISSION_VIEW)]
    public function findings(?string $site = null, array $checks = [], int $limit = 100): array
    {
        return $this->answer($site, fn(GraphModel $graph, $siteModel) => Plugin::getInstance()->api->findings($graph, $siteModel, $checks, $limit));
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'yarn_unused_assets',
        description: 'Assets nothing on the site points at, optionally for one volume (by handle). Candidates for cleanup — read the caveat before deleting any.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission(Plugin::PERMISSION_VIEW)]
    public function unusedAssets(?string $site = null, ?string $volume = null, int $limit = 100): array
    {
        return $this->answer($site, fn(GraphModel $graph, $siteModel) => Plugin::getInstance()->api->unusedAssets($graph, $siteModel, $volume, $limit));
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'yarn_path',
        description: 'The shortest chain of relations from one element to another — "how is this PDF reaching the homepage?". Direction "out" follows what each element points at; "both" ignores direction.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission(Plugin::PERMISSION_VIEW)]
    public function path(int $fromId, int $toId, ?string $site = null, string $direction = GraphModel::DIRECTION_OUT): array
    {
        return $this->answer($site, fn(GraphModel $graph, $siteModel) => Plugin::getInstance()->api->path($graph, $siteModel, $fromId, $toId, $direction));
    }

    /**
     * Resolves the site and the user's masked graph, runs `$answer`, and turns Yarn's own
     * "can't answer that" into an MCP tool error.
     *
     * @param Closure(GraphModel, \craft\models\Site): array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private function answer(?string $site, Closure $answer): array
    {
        $user = Craft::$app->getUser()->getIdentity();

        if (!$user instanceof User) {
            throw new ToolCallException('Authentication is required.');
        }

        $plugin = Plugin::getInstance();

        try {
            $siteModel = $plugin->api->siteForUser($site, $user);

            return $answer($plugin->graph->forUser($plugin->graph->get($siteModel->id), $user), $siteModel);
        } catch (ApiException $e) {
            throw new ToolCallException($e->getMessage());
        }
    }
}
