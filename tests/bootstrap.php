<?php

$loader = require dirname(__DIR__) . '/vendor/autoload.php';

// the Yii class is not part of the composer autoloader
require_once dirname($loader->findFile(\yii\BaseYii::class)) . '/Yii.php';
