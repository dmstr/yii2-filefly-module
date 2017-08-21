<?php

use yii\db\Migration;

class m170815_120101_update_hashmap_table_charset extends Migration
{
    public function up()
    {
        // to prevent syntax error or access violation: 1071 Specified key was too long; max key length is 767 bytes
        $this->alterColumn("filefly_hashmap","path","VARCHAR(255) NOT NULL");
        $this->execute("ALTER TABLE `filefly_hashmap` CONVERT TO CHARACTER SET utf8;");
    }

    public function down()
    {

        return false;
    }
}
