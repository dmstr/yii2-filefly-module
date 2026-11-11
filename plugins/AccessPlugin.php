<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2017 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\plugins;

use hrzg\filefly\components\access\HashmapService;
use hrzg\filefly\components\AccessManager;
use hrzg\filefly\components\Storage;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\PluginInterface;
use yii\base\Component;

/**
 * Class AccessPlugin
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 * @deprecated since 1.9, removed in 2.0 with Flysystem 1: use \hrzg\filefly\components\AccessManager (FileManager::access())
 *
 * The plugins are thin shims: each handle() delegates to an AccessManager owned by the plugin
 * instance, so access decisions stay cached for the lifetime of the plugin as before.
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
     * @deprecated not used any more, kept for subclasses
     */
    protected $permissions = [];

    /**
     * List of tree parents to be checked
     * @var array
     * @deprecated not used any more, kept for subclasses
     */
    protected $_iterator = [];

    /**
     * @var AccessManager|null
     */
    private $_accessManager;

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
        if ($this->_accessManager !== null) {
            $this->_accessManager->setStorage(new Storage(['filesystem' => $filesystem]));
        }
    }

    /**
     * The service this plugin delegates to, created on first use and kept for the plugin's lifetime
     *
     * @return AccessManager
     */
    protected function getAccessManager()
    {
        if ($this->_accessManager === null) {
            $this->_accessManager = new AccessManager(['component' => $this->component]);
            if ($this->filesystem !== null) {
                $this->_accessManager->setStorage(new Storage(['filesystem' => $this->filesystem]));
            }
        }
        return $this->_accessManager;
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
        return HashmapService::normalizePath($path);
    }
}
