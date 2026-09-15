<?php

namespace justinholtweb\yarn\events;

use craft\base\Event;
use justinholtweb\yarn\models\BuildContext;

/**
 * Lets another plugin contribute its own relations to the graph.
 *
 * The case this exists for: a plugin that stores references in its own table — a link field, a
 * bespoke picker, a menu builder — and whose dependencies are therefore invisible to Craft's
 * `relations` table and to Yarn alike.
 *
 * ```php
 * Event::on(Sources::class, Sources::EVENT_REGISTER_EDGE_SOURCES, function(RegisterEdgeSourcesEvent $e) {
 *     $e->sources[] = new MyMenuSource($e->context);
 * });
 * ```
 */
class RegisterEdgeSourcesEvent extends Event
{
    /** @var \justinholtweb\yarn\sources\EdgeSourceInterface[] */
    public array $sources = [];

    /** The context every source is constructed with — site, settings, and the roll-up map. */
    public BuildContext $context;
}
