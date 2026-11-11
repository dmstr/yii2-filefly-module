<?php

// test environment: Application::end() throws ExitException instead of calling exit(),
// Yii does not replace PHPUnit's error and exception handlers
defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENABLE_ERROR_HANDLER') or define('YII_ENABLE_ERROR_HANDLER', false);

$loader = require dirname(__DIR__) . '/vendor/autoload.php';

// the Yii class is not part of the composer autoloader
require_once dirname($loader->findFile(\yii\BaseYii::class)) . '/Yii.php';
