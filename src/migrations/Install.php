<?php

namespace justinholtweb\yarn\migrations;

use craft\db\Migration;
use justinholtweb\yarn\services\Digest;

/**
 * Yarn's one table: the scheduled digest's "last sent" marker.
 *
 * Everything else Yarn knows is either configuration (project config) or derived from Craft's own
 * tables (the graph, rebuilt and cached). The digest marker is neither — it is a fact about the
 * past that has to survive a cache clear, or every clear sends the week's digest again.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        Digest::createTable($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Digest::TABLE);

        return true;
    }
}
