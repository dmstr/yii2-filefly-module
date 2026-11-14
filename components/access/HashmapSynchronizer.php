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

use hrzg\filefly\components\Storage;
use hrzg\filefly\components\storage\ItemInfo;
use hrzg\filefly\components\storage\ItemNotFoundException;
use hrzg\filefly\components\storage\StorageItem;
use hrzg\filefly\models\FileflyHashmap;
use hrzg\filefly\Module;

/**
 * Keeps the hashmap rows in line with the files: creates rows (self-heal, new items), moves them
 * along with renamed items, deletes them with removed items.
 *
 * The only reads from the filesystem are fetchMetadata() and listAllContents().
 *
 * @internal used by \hrzg\filefly\components\AccessManager, not meant to be used directly
 */
class HashmapSynchronizer extends HashmapService
{
    /**
     * The storage of the filesystem the rows belong to
     * @var Storage
     */
    public $storage;

    /**
     * Path form used by self-heal: exactly one leading and no trailing slash.
     *
     * @param string $path
     *
     * @return string normalized path string
     */
    public static function normalizeRepairPath($path)
    {
        return '/' . trim($path, '/');
    }

    /**
     * Check if path has hash and repair with default permissions if not set
     *
     * @param string $path
     * @param bool $repair
     * @param bool $all sync filesystem with database
     *
     * @return bool
     */
    public function check($path, $repair, $all = false)
    {
        // \hrzg\filefly\Module->repair
        if ($repair) {
            if ($all) {

                // cleanup orphan hashes
                $hashes = FileflyHashmap::find()->andWhere(['component' => $this->component])->each(100);
                foreach ($hashes as $hash) {
                    if ($hash->path === '/') {
                        continue;
                    }

                    // try to find in filesystem by hash map path
                    try {
                        $this->fetchMetadata($hash->path);
                    } catch (ItemNotFoundException $e) {
                        \Yii::info($e->getMessage(), __METHOD__);
                        $hash->delete();
                    }
                }

                // add missing hashes
                foreach ($this->listAllContents() as $item) {
                    $this->ensureHash($item->getPath());
                }
            } else {
                // ensure single hash
                $this->ensureHash($path);
            }
        }
        return true;
    }

    /**
     * Create or renew the row of a new item, or move the rows of a renamed or moved item.
     *
     * - `setAccess($path)` (no new path, or `''`): the item at $path was just created (upload,
     *   copy, createFolder). Its row gets the type and size of the item, the acting user as owner
     *   and the access columns from the module's `defaultPermissions`. A row that already exists
     *   with exactly this path (a stale row whose item was gone) is overwritten the same way, as if
     *   it had not existed. No other row is touched; this form never renames anything.
     * - `setAccess($oldPath, $newPath)`: the row of $oldPath and the rows below it are moved to
     *   $newPath (see updateRecursive()). If $oldPath has no row, nothing is moved and a row is
     *   created for $oldPath as in the first form (unchanged from earlier versions).
     * - Rows are matched byte for byte. A row of another path that the database considers equal to
     *   the path to be written (hasConflictingRow()) is never changed: the first form creates no
     *   row, the second form moves nothing; both return false.
     *
     * @param string $oldItemPath
     * @param string|null $newItemPath
     *
     * @return bool
     */
    public function setAccess($oldItemPath = null, $newItemPath = null)
    {
        $oldItemPath = static::normalizePath($oldItemPath);
        $oldHash = $this->findHash($oldItemPath);

        // upload / create
        if ($newItemPath === null || $newItemPath === '') {
            return $this->saveNewItemHash($oldHash !== null ? $oldHash : new FileflyHashmap(), $oldItemPath);
        }

        // rename / move of an item without row
        if ($oldHash === null) {
            return $this->saveNewItemHash(new FileflyHashmap(), $oldItemPath);
        }

        $newItemPath = static::normalizePath($newItemPath);
        // the row of another item that the database considers equal to the new path is not overwritten
        if ($this->hasConflictingRow($newItemPath, $oldHash->path)) {
            \Yii::warning('Not moving the row of [' . $oldItemPath . '], [' . $newItemPath . '] collides with the row of another path', __CLASS__ . '::setAccess');
            return false;
        }

        return $this->updateRecursive($oldHash, $oldHash->path, $newItemPath);
    }

    /**
     * The full path strings of the file or directory to be removed
     *
     * @param string $itemPath
     * @param bool $recursive also remove the rows below the item (paths starting with `<path>/`)
     *
     * @return bool
     */
    public function removeAccess($itemPath = null, $recursive = false)
    {
        // remove all hashmap entries beneath $itemPath
        if ($recursive) {
            $items = $this->findHashesWithPrefix($itemPath . '/');

            if (empty($items)) {
                \Yii::info('Could not find items beneath [' . $itemPath . '] in hash table!', __METHOD__);
            } else {
                foreach ($items as $item) {
                    /** @var FileflyHashmap $item */
                    if (!$item->delete()) {
                        \Yii::error('Could not delete item [' . $item->path . '] in hash table!', __METHOD__);
                        return false;
                    }
                }
            }
        }

        $item = $this->findHash($itemPath);

        if (empty($item)) {
            \Yii::error('Could not find item [' . $itemPath . '] in hash table!', __METHOD__);
            return false;
        } else {
            if (!$item->delete()) {
                \Yii::error('Could not delete item [' . $item->path . '] in hash table!', __METHOD__);
                return false;
            }
        }
        return true;
    }

    /**
     * Check sub items from path, by hashmap rows only
     *
     * @param string $path
     *
     * @return bool true if the path has a row and no row exists beneath it
     */
    public function isEmpty($path)
    {
        $item = $this->findHash($path);

        if (empty($item)) {
            \Yii::info('Path [' . $path . '] not found!', __METHOD__);
            return false;
        }

        // find hashmap items beneath this path
        $items = $this->findHashesWithPrefix($item->path . '/');

        // if no items found => dir is empty
        if (empty($items)) {
            return true;
        }

        \Yii::info('Path [' . $path . '] is not empty!', __METHOD__);
        return false;
    }

    /**
     * Create hash if not exists for path
     *
     * @param string $path
     *
     * @return bool
     */
    private function ensureHash($path)
    {
        $path = static::normalizeRepairPath($path);

        // root folder management is handled by module property `rootFolderManageRole`
        if ($path === '/') {
            return true;
        }

        $hash = $this->findHash($path);

        if ($hash === null) {
            // the path has no row of its own, but the database considers it equal to the path of
            // another item's row: no row can be inserted next to it, and that row is not taken over
            if ($this->hasConflictingRow($path)) {
                \Yii::warning("Not repairing '$path', it collides with the row of another path", __METHOD__);
                return false;
            }

            \Yii::info("Repairing '$path'", __METHOD__);

            $defaultPermissions = FileflyHashmap::accessDefaults();

            list($type, $size) = $this->readTypeAndSize($path, __METHOD__);

            $repairHash = new FileflyHashmap(
                [
                    'component'           => $this->component,
                    'type'                => $type,
                    'path'                => $path,
                    'size'                => $size,
                    Module::ACCESS_OWNER  => $defaultPermissions[Module::ACCESS_OWNER],
                    Module::ACCESS_READ   => $defaultPermissions[Module::ACCESS_READ],
                    Module::ACCESS_UPDATE => $defaultPermissions[Module::ACCESS_UPDATE],
                    Module::ACCESS_DELETE => $defaultPermissions[Module::ACCESS_DELETE],
                ]
            );

            if (!$repairHash->save()) {
                \Yii::error('filefly hash could not been repaired for path ' . $path, __METHOD__);
                return false;
            }
        }
        return true;
    }

    /**
     * Fill a row for the item at $path as for a newly created item and save it: type and size of
     * the item (both `null` if it does not exist), the acting user as owner, the access columns
     * from the module's `defaultPermissions`.
     *
     * @param FileflyHashmap $hash a new row, or the existing row with exactly this path
     * @param string $path
     *
     * @return bool
     */
    private function saveNewItemHash(FileflyHashmap $hash, $path)
    {
        // a new row next to a row the database considers equal: not inserted (see hasConflictingRow())
        if ($hash->getIsNewRecord() && $this->hasConflictingRow($path)) {
            \Yii::warning('Not creating a row for [' . $path . '], it collides with the row of another path', __CLASS__ . '::setAccess');
            return false;
        }

        list($type, $size) = $this->readTypeAndSize($path, __CLASS__ . '::setAccess');

        $defaultPermissions = FileflyHashmap::accessDefaults();
        $hash->setAttributes([
            'component'           => $this->component,
            'type'                => $type,
            'path'                => $path,
            'size'                => $size,
            Module::ACCESS_OWNER  => \Yii::$app->user->id,
            Module::ACCESS_READ   => $defaultPermissions[Module::ACCESS_READ],
            Module::ACCESS_UPDATE => $defaultPermissions[Module::ACCESS_UPDATE],
            Module::ACCESS_DELETE => $defaultPermissions[Module::ACCESS_DELETE],
        ], false);

        if (!$hash->save()) {
            \Yii::error('Could not save new item [' . $path . '] to hash table!', __CLASS__ . '::setAccess');
            return false;
        }
        return true;
    }

    /**
     * Move the row of an item and the rows below it: the row with exactly $oldItemPath and every row
     * whose path starts with `<old>/` get the leading $oldItemPath replaced by $newItemPath. Rows that
     * merely share the prefix (`/dd` for `/d`, `/a.txt.bak` for `/a.txt`) and later occurrences of
     * the old path inside a child path stay as they are.
     *
     * @param FileflyHashmap $itemHash the row with exactly $oldItemPath
     * @param string $oldItemPath
     * @param string $newItemPath
     *
     * @return bool false as soon as one row could not be saved (the rows saved before stay moved)
     */
    private function updateRecursive(FileflyHashmap $itemHash, $oldItemPath, $newItemPath)
    {
        $items = array_merge([$itemHash], $this->findHashesWithPrefix($oldItemPath . '/'));

        foreach ($items as $item) {
            $item->path = $newItemPath . substr($item->path, strlen($oldItemPath));

            if (!$item->save()) {
                \Yii::error('Could not update item [' . $oldItemPath . '] to hash table!', __METHOD__);
                return false;
            }
        }

        return true;
    }

    /**
     * Type and size for a new row; both `null` if the path does not exist
     *
     * @param string $path
     * @param string $logCategory
     *
     * @return array [type, size]
     */
    private function readTypeAndSize($path, $logCategory)
    {
        try {
            $meta = $this->fetchMetadata($path);
            $type = $meta !== null ? $meta->getType() : null;
            $size = $meta !== null ? $meta->getFileSize() : null;
        } catch (ItemNotFoundException $e) {
            \Yii::error($e->getMessage(), $logCategory);
            $type = null;
            $size = null;
        }
        return [$type, $size];
    }

    /**
     * Filesystem read: item info of an existing path.
     *
     * @param string $path
     *
     * @return ItemInfo|null
     * @throws ItemNotFoundException if the path does not exist
     */
    private function fetchMetadata($path)
    {
        return $this->storage->itemInfo($path);
    }

    /**
     * Filesystem read: every file and directory, recursively from the root.
     *
     * @return StorageItem[]
     */
    private function listAllContents()
    {
        return $this->storage->listItems('/', true);
    }
}
