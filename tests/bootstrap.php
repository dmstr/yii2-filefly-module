<?php

// test environment: Application::end() throws ExitException instead of calling exit(),
// Yii does not replace PHPUnit's error and exception handlers
defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENABLE_ERROR_HANDLER') or define('YII_ENABLE_ERROR_HANDLER', false);

$loader = require dirname(__DIR__) . '/vendor/autoload.php';

// the Yii class is not part of the composer autoloader
require_once dirname($loader->findFile(\yii\BaseYii::class)) . '/Yii.php';

// league/flysystem 1.x triggers compile-time E_DEPRECATED on PHP >= 8.4 ("implicitly nullable
// parameter", e.g. Filesystem::get(), Handler::__construct(), FileExistsException::__construct()).
// In isolated test processes PHP prints them to stderr, which PHPUnit reports as test error, and
// compile-time diagnostics bypass error handlers. So all classes of that one package are compiled
// here with E_DEPRECATED masked; deprecations from any other code stay visible.
// TODO: remove with the Flysystem 3 migration
$flysystemSrc = dirname($loader->findFile(\League\Flysystem\Filesystem::class));
$errorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($flysystemSrc, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $class = 'League\\Flysystem\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($flysystemSrc) + 1, -4));
    class_exists($class) || interface_exists($class) || trait_exists($class);
}
error_reporting($errorReporting);
unset($flysystemSrc, $errorReporting, $files, $file, $class);
