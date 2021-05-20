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
     * @return Filesystem
     */
    public static function fileSystem()
    {
        $currentModule = \Yii::$app->controller->module->id;
        $fsComponent = \Yii::$app->getModule($currentModule)->filesystem;
        $fileSystem = \Yii::$app->{$fsComponent};

        $pluginConfig = ['component' => $fsComponent];

        $fileSystem->addPlugin(new SelfHealKit($pluginConfig));
        $fileSystem->addPlugin(new GrantAccess($pluginConfig));
        $fileSystem->addPlugin(new SetAccess($pluginConfig));
        $fileSystem->addPlugin(new RemoveAccess($pluginConfig));
        $fileSystem->addPlugin(new GetPermissions($pluginConfig));
        $fileSystem->addPlugin(new UpdatePermission($pluginConfig));
        $fileSystem->addPlugin(new RecursiveIterator($pluginConfig));

        // disable find, beforeSave, beforeDelete for FileflyHashmap
        FileflyHashmap::$activeAccessTrait = false;

        // disable session flash messages in ActiveRecordAccessTrait
        FileflyHashmap::$enableFlashMessages = false;


        return $fileSystem;
    }

    public static function translate($message)
    {
        return (new Translate(\Yii::$app->language))->{$message};
    }

}
