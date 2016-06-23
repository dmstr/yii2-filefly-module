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

            foreach ($pathParts as $subPath) {

                $directAccess = false;
                $parentAccess = false;

                $pathIterator .= '/' . $subPath;

//                if ($permissionType === 'access_read') {
//                    \Yii::error($pathIterator, '$pathIterator');
//                }

                /** @var $hash \hrzg\filefly\models\FileflyHashmap */
                $query = FileflyHashmap::find($findRaw);
                $query->andWhere(['component' => $this->component]);
                $query->andWhere(['path' => ltrim($pathIterator, '/')]);

                // for direct permission check the permission type column is not null
                if (ltrim($pathIterator, '/') === '/' . $filePath) {
                    //                    $query->andWhere(['not', [$permissionType => null]]);
                    $hash = $query->one();

                    if ($hash !== null) {

//                        if ($permissionType === 'access_read') {
//                            \Yii::error($hash->hasPermission($permissionType), '$hasPermission.direct');
//                        }
                        if ($hash->hasPermission($permissionType)) {
                            $directAccess = true;
                        }
                    }
                } else {
                    $hash = $query->one();

                    if ($hash !== null) {
//                        if ($permissionType === 'access_read') {
//                            \Yii::error($hash->hasPermission($permissionType), '$hasPermission.parent');
//                        }
                        if ($hash->hasPermission($permissionType)) {
                            $parentAccess = true;
                        }
                    }
                }
            }

//            if ($permissionType === 'access_read') {
//                \Yii::error($directAccess, '$directAccess');
//                \Yii::error($parentAccess, '$parentAccess');
//                \Yii::error($this->_allowedFiles, '$this->_allowedFiles.before');
//            }
            if ($directAccess || $parentAccess) {
                $this->_allowedFiles[] = ['path' => $filePath];
            }
//            if ($permissionType === 'access_read') {
//                \Yii::error($directAccess, '$directAccess');
//                \Yii::error($parentAccess, '$parentAccess');
//                \Yii::error($this->_allowedFiles, '$this->_allowedFiles.after');
//            }
        }
        return $this->_allowedFiles;
    }
}