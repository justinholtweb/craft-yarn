<?php

namespace justinholtweb\yarn\events;

use craft\base\Event;
use justinholtweb\yarn\models\Graph;

/**
 * Fired after every built-in check has run, with the findings they produced.
 *
 * Add your own, or drop the ones your site has decided it does not care about — a site that
 * deliberately keeps a library of unused stock imagery does not want to be told about it monthly.
 */
class DefineFindingsEvent extends Event
{
    /** @var \justinholtweb\yarn\models\Finding[] */
    public array $findings = [];

    public Graph $graph;
}
