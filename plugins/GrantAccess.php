<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2017 diemeisterei GmbH, Stuttgart
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
     * internal cache var to reduce subPath iterator checks e.g. in search req.
     *
     * @var array
     */
    private $_iteratorChecked = [];

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
     * @return bool|null
     */
    public function handle($path, $permissionType = 'access_read', $checkParent = true)
    {
        $path = $this->normalize($path);

        // in root path allow access depending on module setting
        if ($path === '/') {
            $currentModule = \Yii::$app->controller->module->id;
            $rootFolderManageRole = \Yii::$app->getModule($currentModule)->rootFolderManageRole;
            if ($rootFolderManageRole && $permissionType !== 'access_read') {
                return \Yii::$app->user->can($rootFolderManageRole);
            } else {
                return true;
            }
        }

        // Grand ALL access for admins
        if (array_key_exists(Module::ACCESS_ROLE_ADMIN, FileflyHashmap::getUsersAuthItems())) {
            return true;
        }

        // do direct permission check for path
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

        // init iterator items cache for this permissionType
        if (!array_key_exists($permissionType, $this->_iteratorChecked)) {
            $this->_iteratorChecked[$permissionType] = [];
        }
        // do recursive permission check for given path
        foreach ($this->_iterator as $subPath) {

            $subPath = $this->normalize($subPath);

            // already checked? hint: value can be null, so isset() can not be used here!
            if (array_key_exists($subPath, $this->_iteratorChecked[$permissionType])) {
                if ($this->_iteratorChecked[$permissionType][$subPath] === null) {
                    continue;
                }
                return $this->_iteratorChecked[$permissionType][$subPath];
            }

            /** @var $hash \hrzg\filefly\models\FileflyHashmap */
            $query = FileflyHashmap::find();
            $query->andWhere(['component' => $this->component]);
            $query->andWhere(['path' => $subPath]);
            $hash = $query->one();

            if ($hash === null) {
                if ($permissionType === Module::ACCESS_UPDATE) {
                    continue;
                }
                $this->_iteratorChecked[$permissionType][$subPath] = false;
                return $this->_iteratorChecked[$permissionType][$subPath];
            }

            if (empty($hash->{$permissionType})) {
                // match if owner right can be granted
                if ($hash->hasPermission($permissionType)) {
                    \Yii::debug("Permission '{$permissionType}' found for {$hash->path}", __METHOD__);
                    $this->_iteratorChecked[$permissionType][$subPath] = true;
                    return $this->_iteratorChecked[$permissionType][$subPath];
                }
                // set value in cache to null, as this will trigger 'cached continue'
                $this->_iteratorChecked[$permissionType][$subPath] = null;
                continue;
            }

            if (!empty($hash->{$permissionType})) {
                // direct or owner permission granted
                $this->_iteratorChecked[$permissionType][$subPath] = $hash->hasPermission($permissionType);
                return $this->_iteratorChecked[$permissionType][$subPath];
            }
        }
        $this->_iteratorChecked[$permissionType][$subPath] = false;
        return false;
    }

    /**
     * built the the path iterations down -> up
     *
     * @param $path
     */
    private function buildIterator($path)
    {
        $this->_iterator = [];
        $path            = $this->normalize($path);
        $parts           = explode('/', $path);
        $countPathParts  = count($parts);
        $subCounter      = $countPathParts;

        for ($i = 0; $i < $countPathParts; $i++) {
            $tmp = '';
            for ($j = 0; $j < $subCounter; $j++) {
                $tmp .= '/' . $parts[$j];
                $tmp = $this->normalize($tmp);
            }
            $subCounter--;
            $this->_iterator[] = $tmp;
        }
    }
}
