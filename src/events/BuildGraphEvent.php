<?php

namespace justinholtweb\yarn\events;

use craft\base\Event;
use justinholtweb\yarn\models\Graph;

/**
 * Fired once a graph is assembled and before it is cached.
 *
 * The place to prune a graph down to what your site considers meaningful, or to add nodes Yarn
 * cannot see. What you do here is baked into the cached copy.
 */
class BuildGraphEvent extends Event
{
    public Graph $graph;
}
