<?php

namespace justinholtweb\yarn\models;

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\helpers\UrlHelper;

/**
 * One element in the graph.
 *
 * Built from plain table rows rather than from element instances. A site with 40,000 assets has
 * 40,000 nodes, and instantiating that many elements to read a filename costs minutes and most of
 * a gigabyte. Everything here is either a column or derived from one.
 */
class Node
{
    public const KIND_ENTRY = 'entry';
    public const KIND_ASSET = 'asset';
    public const KIND_CATEGORY = 'category';
    public const KIND_TAG = 'tag';
    public const KIND_USER = 'user';
    public const KIND_GLOBAL = 'global';
    public const KIND_OTHER = 'other';

    /** The element types Yarn knows how to label and group itself. */
    public const KINDS_BY_TYPE = [
        Entry::class => self::KIND_ENTRY,
        Asset::class => self::KIND_ASSET,
        Category::class => self::KIND_CATEGORY,
        Tag::class => self::KIND_TAG,
        User::class => self::KIND_USER,
        GlobalSet::class => self::KIND_GLOBAL,
    ];

    public function __construct(
        public int $id,
        /** Element class, verbatim from `elements.type` — including types Yarn has never heard of. */
        public string $type,
        public string $kind,
        public string $label,
        /** Section / volume / group name, or '' where the kind has no such thing. */
        public string $group = '',
        /** Stable key for the group, e.g. `section:3`. Used for filtering and colouring. */
        public string $groupKey = '',
        /** Front-end URI in the graph's site, when the element has one. */
        public ?string $uri = null,
        public bool $enabled = true,
        /** Enabled *for this site* — an element can be enabled globally and off in one site. */
        public bool $enabledForSite = true,
        /** Soft-deleted: still in the database, no longer in the site. */
        public bool $deleted = false,
        /** False when the element has no row in `elements_sites` for the graph's site. */
        public bool $inSite = true,
        /** Entry status column (`live`, `pending`, `expired`), where the kind has one. */
        public ?string $entryStatus = null,
        /** Edges arriving. Counted once the whole graph is assembled. */
        public int $inCount = 0,
        /** Edges leaving. */
        public int $outCount = 0,
    ) {
    }

    /**
     * The generic element edit URL.
     *
     * `edit/<id>` routes to `elements/redirect`, which works out the real edit screen for any
     * element type — including ones from plugins. Building `entries/<section>/<id>` by hand would
     * cover entries and nothing else.
     */
    public function cpEditUrl(): string
    {
        return UrlHelper::cpUrl("edit/$this->id");
    }

    public function yarnUrl(?int $siteId = null): string
    {
        return UrlHelper::cpUrl("yarn/element/$this->id", $siteId ? ['site' => $siteId] : []);
    }

    /**
     * A one-word health verdict, used for the dot beside a node everywhere it is listed.
     *
     * Deliberately coarser than Craft's own statuses: what matters in a relation graph is whether
     * following this thread lands you somewhere that renders.
     */
    public function health(): string
    {
        if ($this->deleted) {
            return 'deleted';
        }

        if (!$this->inSite) {
            return 'absent';
        }

        if (!$this->enabled || !$this->enabledForSite) {
            return 'disabled';
        }

        if ($this->entryStatus !== null && $this->entryStatus !== 'live') {
            return $this->entryStatus;
        }

        return 'ok';
    }

    public function isLive(): bool
    {
        return $this->health() === 'ok';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'kind' => $this->kind,
            'label' => $this->label,
            'group' => $this->group,
            'groupKey' => $this->groupKey,
            'uri' => $this->uri,
            'health' => $this->health(),
            'inCount' => $this->inCount,
            'outCount' => $this->outCount,
            'editUrl' => $this->cpEditUrl(),
        ];
    }
}
