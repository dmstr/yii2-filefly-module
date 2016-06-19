<?php

use yii\db\Migration;

class m160619_070000_module_access extends Migration
{
    /**
     * @var array controller all actions
     */
    public $permisions = [
        "filefly" => [
            "name" => "filefly",
            "description" => "filyfly"
        ],
    ];

    /**
     * @var array roles and maping to actions/permisions
     */
    public $roles = [
        "FileflyAdmin" => [
            "filefly",
        ],
    ];

    public function up()
    {

        $permisions = [];
        $auth = \Yii::$app->authManager;

        /**
         * create permisions for each controller action
         */
        foreach ($this->permisions as $action => $permission) {
            $permisions[$action] = $auth->createPermission($permission['name']);
            $permisions[$action]->description = $permission['description'];
            $auth->add($permisions[$action]);
        }

        /**
         *  create roles
         */
        foreach ($this->roles as $roleName => $actions) {
            $role = $auth->createRole($roleName);
            $auth->add($role);

            /**
             *  to role assign permissions
             */
            foreach ($actions as $action) {
                $auth->addChild($role, $permisions[$action]);
            }
        }
    }

    public function down() {
        $auth = Yii::$app->authManager;

        foreach ($this->roles as $roleName => $actions) {
            $role = $auth->createRole($roleName);
            $auth->remove($role);
        }

        foreach ($this->permisions as $permission) {
            $authItem = $auth->createPermission($permission['name']);
            $auth->remove($authItem);
        }
    }
}
