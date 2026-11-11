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
            'filesystem' => \Yii::$app->{$fsComponent},
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

    private static function disableHashmapAccessTrait()
    {
        // disable find, beforeSave, beforeDelete for FileflyHashmap
        FileflyHashmap::$activeAccessTrait = false;

        // disable session flash messages in ActiveRecordAccessTrait
        FileflyHashmap::$enableFlashMessages = false;
    }

}
