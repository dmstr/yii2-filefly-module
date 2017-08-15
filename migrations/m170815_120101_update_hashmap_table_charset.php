<?php

use yii\db\Migration;

class m170815_120101_update_hashmap_table_charset extends Migration
{
    public function up()
    {
        $this->execute("ALTER TABLE `filefly_hashmap` CONVERT TO CHARACTER SET utf8;");
    }

    public function down()
    {

        return false;
    }
}
