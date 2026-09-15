<?php

namespace justinholtweb\yarn\services;

use craft\base\Component;
use justinholtweb\yarn\events\RegisterEdgeSourcesEvent;
use justinholtweb\yarn\models\BuildContext;
use justinholtweb\yarn\sources\ContentSource;
use justinholtweb\yarn\sources\EdgeSourceInterface;
use justinholtweb\yarn\sources\NestedSource;
use justinholtweb\yarn\sources\RelationsSource;
use justinholtweb\yarn\sources\StructureSource;

/**
 * The registry of places relations come from.
 */
class Sources extends Component
{
    /** @see RegisterEdgeSourcesEvent */
    public const EVENT_REGISTER_EDGE_SOURCES = 'registerEdgeSources';

    /** In build order. `relations` first so field edges win the de-duplication tie. */
    public const BUILT_IN = [
        RelationsSource::class,
        NestedSource::class,
        ContentSource::class,
        StructureSource::class,
    ];

    /**
     * Every source, enabled or not.
     *
     * @return EdgeSourceInterface[]
     */
    public function all(BuildContext $context): array
    {
        $sources = [];

        foreach (self::BUILT_IN as $class) {
            $sources[] = new $class($context);
        }

        $event = new RegisterEdgeSourcesEvent([
            'sources' => $sources,
            'context' => $context,
        ]);

        $this->trigger(self::EVENT_REGISTER_EDGE_SOURCES, $event);

        return $event->sources;
    }

    /**
     * The sources that will actually run.
     *
     * @return EdgeSourceInterface[]
     */
    public function enabled(BuildContext $context): array
    {
        return array_values(array_filter($this->all($context), fn(EdgeSourceInterface $s) => $s->isEnabled()));
    }
}
