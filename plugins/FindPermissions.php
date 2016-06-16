<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2016 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\plugins;

use League\Flysystem\FilesystemInterface;
use League\Flysystem\PluginInterface;


/**
 * Class FindPermissions
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class FindPermissions implements PluginInterface
{
    protected $filesystem;

    /**
     * @param FilesystemInterface $filesystem
     */
    public function setFilesystem(FilesystemInterface $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return 'getPermissions';
    }

    /**
     * @param null $action
     * @param array $contents
     *
     * @return bool
     */
    public function handle($action = null, array $contents)
    {
        \Yii::error(\Yii::$app->user->id, 'user.id');
        \Yii::error($contents, 'readPermission.$contents');
        \Yii::error($action, 'readPermission.$action');


        // TODO query contents permissions by giben action


        // TODO return array with files/folders which are accessible for the user
        switch ($action) {
            case 'read':

                break;

            case 'update':

                break;

            case 'delete':

                break;

            default:
                return [];
        }
        return [
            'Test',
            'Test/file_2.jpeg',
            'Test/Unterordner_1',
            'Test/Unterordner_1/file_3.jpeg',
        ];
    }
}