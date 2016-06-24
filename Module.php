<?php

namespace hrzg\filefly;

use dmstr\web\traits\AccessBehaviorTrait;

class Module extends \yii\base\Module
{
    use AccessBehaviorTrait;

    /**
     * Access fields
     */
    const ACCESS_READ = 'access_read';
    const ACCESS_UPDATE = 'access_update';
    const ACCESS_DELETE = 'access_delete';

    /**
     * Module Admin role
     */
    const ADMIN_ACCESS_ALL = 'FileflyAdmin';

    /**
     * The name of the filesystem component
     * @var string
     */
    public $filesystem;

    public $controllerNamespace = 'hrzg\filefly\controllers';

    public function init()
    {
        parent::init();

        // custom initialization code goes here
    }
}
