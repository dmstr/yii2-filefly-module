<?php

namespace hrzg\filefly;

use dmstr\web\traits\AccessBehaviorTrait;

class Module extends \yii\base\Module
{
    use AccessBehaviorTrait;

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
