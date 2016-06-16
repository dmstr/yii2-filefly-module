<?php

use yii\db\Migration;

class m160616_120810_add_filesystem_hash_table extends Migration
{
    // Use safeUp/safeDown to run migration code within a transaction
    public function safeUp()
    {
        $this->createTable(
            'filefly_hashmap',
            [
                'id'            => $this->primaryKey(11),
                'path'          => $this->string(745)->unique()->notNull(),
                'access_domain' => $this->string(255),
                'access_owner'  => $this->integer(11),
                'access_read'   => $this->string(255),
                'access_update' => $this->string(255),
                'access_delete' => $this->string(255),
                'created_at'    => $this->dateTime(),
                'updated_at'    => $this->dateTime(),
            ]
        );
    }

    public function safeDown()
    {
        $this->dropTable('filefly_hashmap');
    }
}
