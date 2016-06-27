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
use hrzg\filefly\Module;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\PluginInterface;
use yii\base\Component;


/**
 * Class GrantPermission
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class GrantPermission extends Component implements PluginInterface
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
        return 'grantPermission';
    }

    /**
     * TODO $contents array param can be a single input!
     * Find permissions for paths by permission type
     *
     * - Parent permission support if no direct permission can be granted
     *
     * @param string $path
     * @param string $permissionType
     *
     * @return array|\yii\db\ActiveRecord[]
     */
    public function handle($path, $permissionType = 'access_read')
    {
        $this->_iterator = [];

        // Grand ALL access for admins
        if (in_array(Module::ADMIN_ACCESS_ALL, array_keys(FileflyHashmap::getUsersAuthItems()))) {
            return true;
        }

        // built path iterations
        $this->buildPathIterator($path);
        \Yii::error($path, '$path');

        foreach ($this->_iterator as $subPath) {
            /** @var $hash \hrzg\filefly\models\FileflyHashmap */
            $query = FileflyHashmap::find();
            $query->andWhere(['component' => $this->component]);
            $query->andWhere(['path' => $subPath]);
            $query->andWhere(['access_domain' => \Yii::$app->language]);
            $hash = $query->one();

            \Yii::error($permissionType, '$permissionType');
            \Yii::error($subPath, '$subPath');

            if ($hash === null) {
                \Yii::error($hash, '$hash');
                if ($permissionType === Module::ACCESS_UPDATE) {
                    continue;
                }
                return false;
            }
            \Yii::error($hash->hasPermission($permissionType), '$perm');

            if (empty($hash->{$permissionType})) {
                \Yii::error('true', 'empty access');

                // match if owner right can be granted
                if ($hash->hasPermission($permissionType)) {
                    return true;
                } else {
                    \Yii::error('continue', 'empty access and no perm');
                    continue;
                }
            }

            if (!empty($hash->{$permissionType})) {

                // direct or owner permission granted
                if ($hash->hasPermission($permissionType)) {
                    return true;
                } else {
                    return false;
                }
            }
        }
        return false;
    }

    /**
     * built the the path iterations down -> up
     *
     * @param $path
     */
    private function buildPathIterator($path)
    {
        $path           = ltrim($path, '/');
        $parts          = explode('/', $path);
        $countPathParts = count($parts);
        $subCounter     = $countPathParts;

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