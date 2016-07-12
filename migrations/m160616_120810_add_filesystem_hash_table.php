<?php

use yii\db\Migration;

class m160616_120810_add_filesystem_hash_table extends Migration
{
    private $tableName = 'filefly_hashmap';

    // Use safeUp/safeDown to run migration code within a transaction
    public function safeUp()
    {
        $this->createTable(
            $this->tableName,
            [
                'id'            => $this->primaryKey(11),
                'component'     => $this->string(45)->notNull(),
                'path'          => $this->string(745)->notNull(),
                'access_owner'  => $this->integer(11),
                'access_read'   => $this->string(255),
                'access_update' => $this->string(255),
                'access_delete' => $this->string(255),
                'created_at'    => $this->dateTime(),
                'updated_at'    => $this->dateTime(),
            ]
        );

        $this->execute("ALTER TABLE `filefly_hashmap` ADD UNIQUE (`component` (45), `path` (200))");
    }

    public function safeDown()
    {
        $this->dropTable($this->tableName);
    }
}
