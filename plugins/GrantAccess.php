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
 * Class GrantAccess
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class GrantAccess extends FilesystemHash implements PluginInterface
{
    /**
     * List of tree parents to be checked
     * @var array
     */
    private $_iterator = [];

    /**
     * @return string
     */
    public function getMethod()
    {
        return 'grantAccess';
    }

    /**
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
        // Grand ALL access for admins
        if (in_array(Module::ACCESS_ROLE_ADMIN, array_keys(FileflyHashmap::getUsersAuthItems()))) {
            return true;
        }

        // built path iterations
        $this->buildPathIterator($path);

        foreach ($this->_iterator as $subPath) {
            /** @var $hash \hrzg\filefly\models\FileflyHashmap */
            $query = FileflyHashmap::find();
            $query->andWhere(['component' => $this->component]);
            $query->andWhere(['path' => $subPath]);
            $hash = $query->one();

            if ($hash === null) {

                if ($permissionType === Module::ACCESS_UPDATE) {
                    continue;
                }

                return false;
            }

            if (empty($hash->{$permissionType})) {
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
        $this->_iterator = [];

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