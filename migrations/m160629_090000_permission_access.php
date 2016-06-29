<?php

use yii\db\Migration;

class m160629_090000_permission_access extends Migration
{
    /**
     * @var array roles
     */
    public $roles = [
        "FileflyPermissions" => [
            "index",
        ],
    ];

    public function up()
    {
        $auth = \Yii::$app->authManager;

        /**
         *  create roles
         */
        foreach ($this->roles as $roleName => $actions) {
            $role = $auth->createRole($roleName);
            $auth->add($role);
        }

        $auth->addChild($auth->getRole('FileflyAdmin'), $auth->getRole('FileflyPermissions'));
    }

    public function down()
    {
        $auth = Yii::$app->authManager;

        foreach ($this->roles as $roleName => $actions) {
            $role = $auth->createRole($roleName);
            $auth->remove($role);
        }
    }
}
