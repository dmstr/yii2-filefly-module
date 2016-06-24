<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2016 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\plugins;

use hrzg\filefly\models\FileflyHashmap;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\PluginInterface;
use yii\base\Component;


/**
 * Class FindPermissions
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class FindPermissions extends Component implements PluginInterface
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
     * List of tree parents to be checked
     * @var array
     */
    private $_iterator = [];

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
        return 'findPermissions';
    }

    /**
     * Find permissions for paths by permission type
     *
     * - Parent permission support if no direct permission can be granted
     *
     * @param array $contents
     * @param string $permissionType
     * @param bool $findRaw
     *
     * @return array|\yii\db\ActiveRecord[]
     */
    public function handle(array $contents, $permissionType = 'access_read', $findRaw = false)
    {
        $this->_iterator     = [];

        foreach ($contents as $file) {

            if (is_array($file) && array_key_exists('path', $file)) {
                $filePath = ltrim($file['path'], '/');
            } else {
                $filePath = $file;
            }

            // built path iterations
            $this->buildPathIterator($filePath);

            foreach ($this->_iterator as $subPath) {

                $subPath = ltrim($subPath, '/');

                /** @var $hash \hrzg\filefly\models\FileflyHashmap */
                $query = FileflyHashmap::find($findRaw);
                $query->andWhere(['component' => $this->component]);
                $query->andWhere(['path' => $subPath]);
                $hash = $query->one();

                if ($hash === null) {
                    continue;
                }

                if (!empty($hash->{$permissionType})) {
                    if (!$hash->hasPermission($permissionType)) {
                        break;
                    } else {
                        return ['path' => $filePath];
                    }
                }
            }
        }
        return [];
    }

    /**
     * built the the path iterations down -> up
     * @param $path
     */
    private function buildPathIterator($path) {
        $parts          = explode('/', $path);
        $countPathParts = count($parts);
        $subCounter = count($parts);

        for ($i = 0; $i < $countPathParts; $i++) {
            $tmp = '';
            for ($j = 0; $j < $subCounter; $j++) {
                $tmp .= '/' . $parts[$j];

            }
            $subCounter--;
            $this->_iterator[] = $tmp;
        }
    }
}