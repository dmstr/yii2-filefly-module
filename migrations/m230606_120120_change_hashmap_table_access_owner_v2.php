<?php

use yii\db\Migration;

class m230606_120120_change_hashmap_table_access_owner_v2 extends Migration
{
    public function up()
    {
        // allow uuid and longer strings as access_owner value
        $this->alterColumn("filefly_hashmap","access_owner", $this->string(255));
    }

    public function down()
    {
        return false;
    }
}
