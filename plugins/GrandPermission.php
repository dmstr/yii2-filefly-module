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
 * Class GrandPermission
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class GrandPermission implements PluginInterface
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
        return 'can';
    }


    /**
     * @param null $item
     * @param array $permissions
     *
     * @return bool
     */
    public function handle($item = null, array $permissions)
    {
        \Yii::error($item, 'can.$item');
        \Yii::error($permissions, 'can.$permissions');

        return (in_array($item['path'], $permissions)) ? true : false;
    }
}