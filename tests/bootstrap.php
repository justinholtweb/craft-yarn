<?php

/**
 * Bootstrap for the unit suite.
 *
 * Plain PHP — no Craft application, no database. What runs here is the part of Yarn that is
 * deliberately pure: the graph algorithms, the export writers, and the reference tag grammar.
 * Those are where a subtle mistake produces a wrong answer rather than an error, so they are the
 * parts worth pinning down away from a live site.
 *
 * Everything that needs real content is exercised by tests/integration/checks.php instead.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
