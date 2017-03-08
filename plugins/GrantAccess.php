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


/**
 * Class GrantAccess
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class GrantAccess extends AccessPlugin
{
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
     * @param bool $checkParent
     *
     * @return array|\yii\db\ActiveRecord[]
     */
    public function handle($path, $permissionType = 'access_read', $checkParent = true)
    {
        $path = $this->normalize($path);

        // in root path allow access to all
        if ($path === '/') {
            return true;
        }

        // Grand ALL access for admins
        if (in_array(Module::ACCESS_ROLE_ADMIN, array_keys(FileflyHashmap::getUsersAuthItems()))) {
            return true;
        }

        // do direct permission check for item
        if ($checkParent === false) {

            /** @var $hash \hrzg\filefly\models\FileflyHashmap */
            $query = FileflyHashmap::find();
            $query->andWhere(['component' => $this->component]);
            $query->andWhere(['path' => $path]);
            $hash = $query->one();

            // return null for empty permission field, will check if any parent access can be granted
            if (empty($hash->{$permissionType})) {
                return null;
            }

            if ($hash !== null) {
                return $hash->hasPermission($permissionType);
            }
            return false;
        }

        // build path iterator list
        $this->buildIterator($path);

        foreach ($this->_iterator as $subPath) {

            $subPath = $this->normalize($subPath);

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
                return $hash->hasPermission($permissionType);
            }
        }
        return false;
    }

    /**
     * built the the path iterations down -> up
     *
     * @param $path
     */
    private function buildIterator($path)
    {
        $path           = $this->normalize($path);
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
