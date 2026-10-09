<?php

namespace justinholtweb\yarn\migrations;

use craft\db\Migration;
use justinholtweb\yarn\services\Digest;

/**
 * Adds the scheduled findings digest's "last sent" marker (5.1).
 */
class m261009_000000_digests extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Digest::TABLE)) {
            Digest::createTable($this);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Digest::TABLE);

        return true;
    }
}
