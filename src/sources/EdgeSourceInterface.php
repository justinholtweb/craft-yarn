<?php

namespace justinholtweb\yarn\sources;

use justinholtweb\yarn\models\Edge;

/**
 * A place relations come from.
 *
 * Craft's own `relations` table is one of four, and on most real sites it is not the one that
 * surprises people. A link typed into a rich-text field is every bit as much a dependency as an
 * Entries field, and deleting its target breaks the page just the same.
 *
 * Third-party sources are registered through {@see \justinholtweb\yarn\events\RegisterEdgeSourcesEvent}.
 */
interface EdgeSourceInterface
{
    /** Stable identifier, matching the `sources` setting where the source is optional. */
    public static function id(): string;

    /** Name shown wherever Yarn reports where an edge came from. */
    public function displayName(): string;

    /** Whether this source should run, given the settings it was constructed with. */
    public function isEnabled(): bool;

    /**
     * Whether a reader can switch this source on under Settings.
     *
     * False for the structural ones — the relations table, which is not optional, and nested
     * ownership, which is the inverse of the roll-up setting rather than a source of its own.
     * Telling somebody to "switch on nested" would send them looking for a checkbox that is not
     * there and never will be.
     */
    public function isOptional(): bool;

    /**
     * Every edge this source can see, already rolled up.
     *
     * A generator rather than an array: the content sources walk every field value on the site,
     * and materialising the lot before the graph can start absorbing them doubles peak memory for
     * no gain.
     *
     * @return iterable<Edge>
     */
    public function collect(): iterable;
}
