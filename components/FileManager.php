<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2020 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\components;

use creocoder\flysystem\Filesystem;
use hrzg\filefly\models\FileflyHashmap;
use hrzg\filefly\plugins\GetPermissions;
use hrzg\filefly\plugins\GrantAccess;
use hrzg\filefly\plugins\RecursiveIterator;
use hrzg\filefly\plugins\RemoveAccess;
use hrzg\filefly\plugins\SelfHealKit;
use hrzg\filefly\plugins\SetAccess;
use hrzg\filefly\plugins\UpdatePermission;
use yii\base\Component;

/**
 * --- PRIVATE PROPERTIES ---
 *
 * @property Filesystem $_filesystem
 *
 * @method Filesystem check
 * @method Filesystem grantAccess
 */
class FileManager extends Component
{

    const ACCESS_OWNER = 'access_owner';
    const ACCESS_READ = 'access_read';
    const ACCESS_UPDATE = 'access_update';
    const ACCESS_DELETE = 'access_delete';

    /**
     * The filesystem component of the current module.
     *
     * The plugins registered here are deprecated shims for code outside this module: the magic
     * methods check(), grantAccess(), setAccess(), removeAccess(), getPermissions(),
     * updatePermission() and isEmpty() still work on the returned filesystem, but new code uses
     * access() instead. They are removed in 2.0 together with Flysystem 1.
     *
     * Side effect: switches the access trait and its flash messages off for FileflyHashmap globally.
     *
     * @return Filesystem
     * @deprecated since 1.9, removed in 2.0 with Flysystem 1: the module itself uses storage() for
     *             files and access() for permissions; this method is kept for code outside the module
     */
    public static function fileSystem()
    {
        $fsComponent = self::currentFilesystemComponent();
        $fileSystem = \Yii::$app->{$fsComponent};

        $pluginConfig = ['component' => $fsComponent];

        $fileSystem->addPlugin(new SelfHealKit($pluginConfig));
        $fileSystem->addPlugin(new GrantAccess($pluginConfig));
        $fileSystem->addPlugin(new SetAccess($pluginConfig));
        $fileSystem->addPlugin(new RemoveAccess($pluginConfig));
        $fileSystem->addPlugin(new GetPermissions($pluginConfig));
        $fileSystem->addPlugin(new UpdatePermission($pluginConfig));
        $fileSystem->addPlugin(new RecursiveIterator($pluginConfig));

        self::disableHashmapAccessTrait();

        return $fileSystem;
    }

    /**
     * The storage facade for the filesystem of the current module: the only way the module reads
     * and writes files.
     *
     * Returns a new instance on every call; it holds no state besides the filesystem component.
     *
     * Side effect: switches the access trait and its flash messages off for FileflyHashmap globally,
     * like fileSystem() and access(). Unlike fileSystem() it registers no plugins on the component.
     *
     * @return Storage
     */
    public static function storage()
    {
        $fsComponent = self::currentFilesystemComponent();
        $storage = self::createStorage($fsComponent);

        self::disableHashmapAccessTrait();

        return $storage;
    }

    /**
     * Access control and hashmap maintenance for the filesystem of the current module.
     *
     * Returns a new instance on every call, so access decisions are cached per call site (one
     * action) and never across requests.
     *
     * Side effect: switches the access trait and its flash messages off for FileflyHashmap globally,
     * like fileSystem().
     *
     * @return AccessManager
     */
    public static function access()
    {
        $fsComponent = self::currentFilesystemComponent();

        self::disableHashmapAccessTrait();

        return new AccessManager([
            'component' => $fsComponent,
            'storage' => self::createStorage($fsComponent),
        ]);
    }

    public static function translate($message)
    {
        return (new Translate(\Yii::$app->language))->{$message};
    }

    /**
     * The yii component name of the filesystem of the module that runs the current controller
     *
     * @return string
     */
    private static function currentFilesystemComponent()
    {
        $currentModule = \Yii::$app->controller->module->id;
        return \Yii::$app->getModule($currentModule)->filesystem;
    }

    /**
     * @param string $fsComponent yii component name of the filesystem
     *
     * @return Storage
     */
    private static function createStorage($fsComponent)
    {
        return new Storage(['filesystem' => \Yii::$app->{$fsComponent}]);
    }

    private static function disableHashmapAccessTrait()
    {
        // disable find, beforeSave, beforeDelete for FileflyHashmap
        FileflyHashmap::$activeAccessTrait = false;

        // disable session flash messages in ActiveRecordAccessTrait
        FileflyHashmap::$enableFlashMessages = false;
    }

}
