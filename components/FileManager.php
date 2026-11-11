<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2020 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\components;

use hrzg\filefly\models\FileflyHashmap;
use yii\base\Component;

/**
 * Entry points of the module: storage() for files, access() for permissions and the hashmap.
 */
class FileManager extends Component
{

    const ACCESS_OWNER = 'access_owner';
    const ACCESS_READ = 'access_read';
    const ACCESS_UPDATE = 'access_update';
    const ACCESS_DELETE = 'access_delete';

    /**
     * The storage facade for the filesystem of the current module: the only way the module reads
     * and writes files.
     *
     * Returns a new instance on every call; it holds no state besides the filesystem component.
     *
     * Side effect: switches the access trait and its flash messages off for FileflyHashmap globally,
     * like access().
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
     * like storage().
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
