<?php

use yii\db\Migration;

class m230606_120101_change_hashmap_table_access_owner extends Migration
{
    public function up()
    {
        // allow uuid as access_owner value
        $this->alterColumn("filefly_hashmap","access_owner", $this->string(36));
    }

    public function down()
    {
        return false;
    }
}
