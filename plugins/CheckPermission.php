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
 * Class CheckPermission
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class CheckPermission implements PluginInterface
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
        return 'checkPermission';
    }

    /**
     * The path string of the file or directory to be checked
     *
     * @param string $item
     * @param array $files
     *
     * @return bool
     */
    public function handle($item = null, array $files)
    {
        if (empty($files)) {
            return false;
        }

        foreach ($files as $file) {
            if (in_array($item['path'], $file)) {
                return true;
            }
        }
        return false;
    }
}
