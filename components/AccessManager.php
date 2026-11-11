<?php
// file generated with AI assistance: Claude Code - [2026-11-11 18:00:00 UTC]
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2017 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\components;

use hrzg\filefly\components\access\HashmapSynchronizer;
use hrzg\filefly\components\access\PermissionResolver;
use yii\base\BaseObject;

/**
 * Access control and hashmap maintenance for the items of one filesystem component.
 *
 * Replaced the Flysystem plugins of 1.x (removed in 2.0). Obtain an instance for the current
 * module with FileManager::access(). An instance caches access decisions (grantAccess()), so it
 * should not outlive one request.
 *
 * --- PUBLIC PROPERTIES ---
 *
 * @property Storage $storage
 */
class AccessManager extends BaseObject
{
    /**
     * The yii component name of the filesystem, stored in the `component` column of the hashmap
     * @var string
     */
    public $component;

    /**
     * @var Storage|null
     */
    private $_storage;

    /**
     * @var PermissionResolver|null
     */
    private $_permissions;

    /**
     * @var HashmapSynchronizer|null
     */
    private $_hashmap;

    /**
     * The storage of the filesystem the hashmap rows belong to; defaults to the storage of the
     * application component `$component`, created on first use
     *
     * @return Storage
     */
    public function getStorage()
    {
        if ($this->_storage === null) {
            $this->_storage = new Storage(['filesystem' => \Yii::$app->get($this->component)]);
        }
        return $this->_storage;
    }

    /**
     * @param Storage $storage
     */
    public function setStorage(Storage $storage)
    {
        $this->_storage = $storage;
        // the synchronizer is stateless; the access cache of the resolver does not depend on the storage
        $this->_hashmap = null;
    }

    /**
     * Check if path has a hashmap row and create it with the default permissions if not
     *
     * @param string $path
     * @param bool $repair nothing happens unless true (\hrzg\filefly\Module::$repair)
     * @param bool $all sync the whole filesystem with the hashmap: delete orphan rows, add missing ones
     *
     * @return bool always true
     */
    public function check($path, $repair, $all = false)
    {
        return $this->hashmap()->check($path, $repair, $all);
    }

    /**
     * Find permissions for paths by permission type, with parent permission support
     *
     * @param string $path
     * @param string $permissionType one of the `access_*` columns
     * @param bool $checkParent false: decide on the row of the path alone
     *
     * @return bool|null null only with $checkParent false and an empty column (or no row)
     */
    public function grantAccess($path, $permissionType = 'access_read', $checkParent = true)
    {
        return $this->permissions()->grantAccess($path, $permissionType, $checkParent);
    }

    /**
     * `setAccess($path)`: create or renew the row of a newly created item (acting user as owner,
     * access columns from the defaults; an existing row of exactly this path is overwritten, no
     * other row changes). `setAccess($oldPath, $newPath)`: move the row of a renamed or moved item
     * and the rows below it. See HashmapSynchronizer::setAccess().
     *
     * @param string $oldItemPath
     * @param string|null $newItemPath
     *
     * @return bool
     */
    public function setAccess($oldItemPath = null, $newItemPath = null)
    {
        return $this->hashmap()->setAccess($oldItemPath, $newItemPath);
    }

    /**
     * Delete the row of an item, with $recursive also the rows beneath it
     *
     * @param string $itemPath
     * @param bool $recursive
     *
     * @return bool
     */
    public function removeAccess($itemPath = null, $recursive = false)
    {
        return $this->hashmap()->removeAccess($itemPath, $recursive);
    }

    /**
     * Whether the current user may read and change the permissions of the item at $path: its owner,
     * RBAC `Admin` or `FileflyAdmin`. See PermissionResolver::canManagePermissions().
     *
     * @param string $path
     *
     * @return bool
     */
    public function canManagePermissions($path)
    {
        return $this->permissions()->canManagePermissions($path);
    }

    /**
     * Permissions of a path for the permission modal
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getPermissions($path)
    {
        return $this->permissions()->getPermissions($path);
    }

    /**
     * Write the roles selected in the permission modal to the row of a path
     *
     * @param array $item
     * @param string $path
     *
     * @return bool
     */
    public function updatePermission($item, $path)
    {
        return $this->permissions()->updatePermission($item, $path);
    }

    /**
     * Whether no hashmap row exists beneath the path (false if the path itself has no row)
     *
     * @param string $path
     *
     * @return bool
     */
    public function isEmpty($path)
    {
        return $this->hashmap()->isEmpty($path);
    }

    /**
     * @return PermissionResolver
     */
    private function permissions()
    {
        if ($this->_permissions === null) {
            $this->_permissions = new PermissionResolver(['component' => $this->component]);
        }
        return $this->_permissions;
    }

    /**
     * @return HashmapSynchronizer
     */
    private function hashmap()
    {
        if ($this->_hashmap === null) {
            $this->_hashmap = new HashmapSynchronizer([
                'component' => $this->component,
                'storage' => $this->getStorage(),
            ]);
        }
        return $this->_hashmap;
    }
}
