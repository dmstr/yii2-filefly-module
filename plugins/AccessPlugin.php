<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2017 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\plugins;

use League\Flysystem\FilesystemInterface;
use League\Flysystem\PluginInterface;
use yii\base\Component;

/**
 * Class AccessPlugin
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
abstract class AccessPlugin extends Component implements PluginInterface
{
    /**
     * The yii component name of this filesystem
     * @var string
     */
    public $component;

    /**
     * @var FilesystemInterface $filesystem
     */
    protected $filesystem;

    /**
     * @var array
     */
    protected $permissions = [];

    /**
     * List of tree parents to be checked
     * @var array
     */
    protected $_iterator = [];

    /**
     * Get the method name.
     *
     * @return string
     */
    abstract public function getMethod();

    /**
     * @param FilesystemInterface $filesystem
     */
    public function setFilesystem(FilesystemInterface $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * ensure one beginning forward slash
     *
     * @param string $path
     *
     * @return string normalized path string
     */
    protected function normalize($path)
    {
        if(empty($path)) {
            return $path;
        }

        return '/' . ltrim($path, '/');
    }
}
