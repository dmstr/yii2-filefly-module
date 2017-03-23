<?php

use yii\db\Migration;

class m170323_100000_update_hashmap_table extends Migration
{
    public function up()
    {
        $this->addColumn('filefly_hashmap', 'type', 'VARCHAR(32) NULL after `component`');
        $this->addColumn('filefly_hashmap', 'size', 'BIGINT NULL after `path`');
    }

    public function down()
    {
        $this->dropColumn('filefly_hashmap', 'type');
        $this->dropColumn('filefly_hashmap', 'size');
    }
}
