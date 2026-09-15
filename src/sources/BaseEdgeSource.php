<?php

namespace justinholtweb\yarn\sources;

use justinholtweb\yarn\models\BuildContext;

/**
 * Shared plumbing: the build context, and the default "on unless the settings say otherwise".
 */
abstract class BaseEdgeSource implements EdgeSourceInterface
{
    public function __construct(protected BuildContext $context)
    {
    }

    /**
     * Optional sources are listed in the `sources` setting; the rest are structural and always on.
     */
    public function isEnabled(): bool
    {
        return $this->context->settings->uses(static::id());
    }

    public function isOptional(): bool
    {
        return true;
    }
}
