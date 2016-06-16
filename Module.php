<?php

namespace hrzg\filefly;

use dmstr\web\traits\AccessBehaviorTrait;

class Module extends \yii\base\Module
{
    // TODO Api controller!
    #use AccessBehaviorTrait;

    public $controllerNamespace = 'hrzg\filefly\controllers';

    public function init()
    {
        parent::init();

        // custom initialization code goes here
    }
}
