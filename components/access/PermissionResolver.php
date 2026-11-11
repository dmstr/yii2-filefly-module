<?php
// file generated with AI assistance: Claude Code - [2026-11-11 18:00:00 UTC]
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2017 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\components\access;

use hrzg\filefly\models\FileflyHashmap;
use hrzg\filefly\Module;

/**
 * Evaluates and edits the access columns of hashmap rows; needs no filesystem.
 *
 * Holds a per-instance cache of path decisions for grantAccess(), so one instance should live no
 * longer than one request (FileManager::access() creates a new one on every call).
 *
 * @internal used by \hrzg\filefly\components\AccessManager, not meant to be used directly
 */
class PermissionResolver extends HashmapService
{
    /**
     * internal cache var to reduce subPath iterator checks e.g. in search req.
     *
     * @var array
     */
    private $_iteratorChecked = [];

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
    public function grantAccess($path, $permissionType = 'access_read', $checkParent = true)
    {
        $path = static::normalizePath($path);

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

            $hash = $this->findHash($path);

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
        $iterator = $this->buildIterator($path);

        // init iterator items cache for this permissionType
        if (!array_key_exists($permissionType, $this->_iteratorChecked)) {
            $this->_iteratorChecked[$permissionType] = [];
        }
        // do recursive permission check for given path
        foreach ($iterator as $subPath) {

            $subPath = static::normalizePath($subPath);

            // already checked? hint: value can be null, so isset() can not be used here!
            if (array_key_exists($subPath, $this->_iteratorChecked[$permissionType])) {
                if ($this->_iteratorChecked[$permissionType][$subPath] === null) {
                    continue;
                }
                return $this->_iteratorChecked[$permissionType][$subPath];
            }

            $hash = $this->findHash($subPath);

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
     * Whether the current user may read and change the permissions of the item at $path
     * (`resolvePermissions`, `changePermissions`): the owner of the item's own row, a user with the
     * RBAC role of ActiveRecordAccessTrait (`Admin`), or a user with the module's admin bypass
     * (`FileflyAdmin` among the user's auth items, as in grantAccess()).
     *
     * - Roles or `*` in the access columns do not count, and ownership is not inherited: the row with
     *   exactly this path decides, the row getPermissions() and updatePermission() read and write.
     * - Without a row there is no owner, so only admins pass. The check creates no row.
     * - The root (`/`, also given as `` or with extra slashes) is managed by admins only, also when a
     *   row for `/` names the user as owner.
     *
     * @param string $path
     *
     * @return bool
     */
    public function canManagePermissions($path)
    {
        if ($this->isAdmin()) {
            return true;
        }

        if (HashmapSynchronizer::normalizeRepairPath($path) === '/') {
            return false;
        }

        $hash = $this->findHash($path);
        return $hash !== null && $this->isOwner($hash);
    }

    /**
     * Build permissions array for multi selects in permission modal
     *
     * @param string $path
     *
     * @return array|false false if the path has no hashmap row
     */
    public function getPermissions($path)
    {
        $permissions = [];
        $permissions['read'] = null;
        $permissions['update'] = null;
        $permissions['delete'] = null;

        $hash = $this->findHash($path);

        if ($hash === null) {
            return false;
        }

        $userAuthItems = array_keys(FileflyHashmap::getUsersAuthItems());

        $permissions['read'] = $this->buildSelection($hash, Module::ACCESS_READ, $userAuthItems);
        $permissions['update'] = $this->buildSelection($hash, Module::ACCESS_UPDATE, $userAuthItems);
        $permissions['delete'] = $this->buildSelection($hash, Module::ACCESS_DELETE, $userAuthItems);

        return $permissions;
    }

    /**
     * Write the roles selected in the permission modal into the access columns of the row
     *
     * @param array $item with the keys `authRead`, `authUpdate`, `authDelete`, each a list of `['role' => ...]`
     * @param string $path
     *
     * @return bool
     */
    public function updatePermission($item, $path)
    {
        $hash = $this->findHash($path);

        if ($hash === null) {
            return false;
        }

        $readItems = [];
        foreach ($item['authRead'] as $readItem) {
            $readItems[$readItem['role']] = $readItem['role'];
        }

        $updateItems = [];
        foreach ($item['authUpdate'] as $updateItem) {
            $updateItems[$updateItem['role']] = $updateItem['role'];
        }

        $deleteItems = [];
        foreach ($item['authDelete'] as $deleteItem) {
            $deleteItems[$deleteItem['role']] = $deleteItem['role'];
        }

        // set access permissions
        $hash->authItemArrayToString(Module::ACCESS_READ, $readItems);
        $hash->authItemArrayToString(Module::ACCESS_UPDATE, $updateItems);
        $hash->authItemArrayToString(Module::ACCESS_DELETE, $deleteItems);

        if (!$hash->save()) {
            \Yii::error('Could not update item [' . $path . '] in hash table!', __METHOD__);
            return false;
        }
        return true;
    }

    /**
     * One access type of getPermissions(): for each auth item of the user `['role', 'selected']`.
     *
     * An entry is only written while iterating the items of the column, so a column without items
     * leaves the whole list `null`.
     *
     * @param FileflyHashmap $hash
     * @param string $permissionType
     * @param string[] $userAuthItems
     *
     * @return array|null
     */
    private function buildSelection(FileflyHashmap $hash, $permissionType, array $userAuthItems)
    {
        $selection = null;
        $pos = 0;
        foreach ($userAuthItems as $authItem) {
            $selected = false;
            foreach ($hash->authItemStringToArray($permissionType) as $columnItem) {
                if ($authItem === $columnItem) {
                    $selected = true;
                }
                $selection[$pos] = ['role' => $authItem, 'selected' => $selected];
            }
            $pos++;
        }
        return $selection;
    }

    /**
     * RBAC `Admin` (FileflyHashmap::hasPermission() grants it everything) or the module's admin
     * bypass `FileflyAdmin` (grantAccess())
     *
     * @return bool
     */
    private function isAdmin()
    {
        return \Yii::$app->user->can(FileflyHashmap::getAdminRole())
            || array_key_exists(Module::ACCESS_ROLE_ADMIN, FileflyHashmap::getUsersAuthItems());
    }

    /**
     * The owner test of FileflyHashmap::hasPermission(): a logged in user whose id equals the
     * `access_owner` of the row (loose comparison, so `05` matches user 5)
     *
     * @param FileflyHashmap $hash
     *
     * @return bool
     */
    private function isOwner(FileflyHashmap $hash)
    {
        return !\Yii::$app->user->isGuest
            && $hash->getOldAttribute(Module::ACCESS_OWNER) == (string)\Yii::$app->user->getId();
    }

    /**
     * built the the path iterations down -> up
     *
     * @param string $path
     *
     * @return string[]
     */
    private function buildIterator($path)
    {
        $iterator = [];
        $path = static::normalizePath($path);
        $parts = explode('/', $path);
        $countPathParts = count($parts);
        $subCounter = $countPathParts;

        for ($i = 0; $i < $countPathParts; $i++) {
            $tmp = '';
            for ($j = 0; $j < $subCounter; $j++) {
                $tmp .= '/' . $parts[$j];
                $tmp = static::normalizePath($tmp);
            }
            $subCounter--;
            $iterator[] = $tmp;
        }
        return $iterator;
    }
}
