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

    protected $filesystem;

    private $_allowedFiles = [];

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
        $this->_allowedFiles = [];

        foreach ($contents as $file) {

            if (is_array($file) && array_key_exists('path', $file)) {
                $filePath = $file['path'];
            } else {
                $filePath = $file;
            }

            $pathIterator = '';
            $pathParts    = explode('/', ltrim($filePath, '/'));
            $directAccess = false;
            $parentAccess = false;

            foreach ($pathParts as $subPath) {

                $pathIterator .= '/' . $subPath;

                /** @var $hash \hrzg\filefly\models\FileflyHashmap */
                $query = FileflyHashmap::find($findRaw);
                $query->andWhere(['component' => $this->component]);
                $query->andWhere(['path' => ltrim($pathIterator, '/')]);
                $hash = $query->one();

                // for direct permission check the permission type column is not null
                if ($pathIterator === $filePath) {
                    if ($hash !== null && !$hash->hasPermission($permissionType)) {
                        if ($hash->{$permissionType} !== null) {
                            $parentAccess = false;
                        }
                    } else {
                        $directAccess = true;
                        break;
                    }
                }

                if ($hash !== null && $hash->hasPermission($permissionType)) {
                    $parentAccess = true;
                }
            }
            // add file or path if direct or parent access was granted
            if ($directAccess || $parentAccess) {
                $this->_allowedFiles[] = ['path' => $filePath];
            }
        }
        return $this->_allowedFiles;
    }
}