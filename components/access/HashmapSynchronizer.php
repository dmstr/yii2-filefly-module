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
     * The full path strings of the file or directory to be set or updated
     *
     * Without a row for $oldItemPath a new row is created for $oldItemPath (upload / create),
     * otherwise every row matching `LIKE '<old>%'` is rewritten to $newItemPath.
     *
     * @param string $oldItemPath
     * @param string $newItemPath
     *
     * @return bool
     */
    public function setAccess($oldItemPath = null, $newItemPath = null)
    {
        $oldItemPath = static::normalizePath($oldItemPath);
        $newItemPath = static::normalizePath($newItemPath);

        $oldHash = $this->findHash($oldItemPath);

        // upload / create
        if (empty($oldHash)) {

            list($type, $size) = $this->readTypeAndSize($oldItemPath, __METHOD__);

            $defaultPermissions = FileflyHashmap::accessDefaults();
            $newHash = new FileflyHashmap(
                [
                    'component'    => $this->component,
                    'type'         => $type,
                    'path'         => $oldItemPath,
                    'size'         => $size,
                    'access_owner' => \Yii::$app->user->id,
                    Module::ACCESS_READ   => $defaultPermissions[Module::ACCESS_READ],
                    Module::ACCESS_UPDATE => $defaultPermissions[Module::ACCESS_UPDATE],
                    Module::ACCESS_DELETE => $defaultPermissions[Module::ACCESS_DELETE],
                ]
            );
            if (!$newHash->save()) {
                \Yii::error('Could not save new item [' . $oldItemPath . '] to hash table!', __METHOD__);
                return false;
            }
        } else {
            return $this->updateRecursive($oldHash->path, $newItemPath);
        }

        return true;
    }

    /**
     * The full path strings of the file or directory to be removed
     *
     * @param string $itemPath
     * @param bool $recursive also remove all rows matching `LIKE '<path>/%'`
     *
     * @return bool
     */
    public function removeAccess($itemPath = null, $recursive = false)
    {
        // remove all hashmap entries beneath $itemPath
        if ($recursive) {
            $items = $this->findHashesLike($itemPath . '/%');

            if (empty($items)) {
                \Yii::info('Could not find items beneath [' . $itemPath . '] in hash table!', __METHOD__);
            } else {
                foreach ($items as $item) {
                    /** @var $item FileflyHashmap */
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
        $items = $this->findHashesLike($item->path . '/%');

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
     * @param string $oldItemPath
     * @param string $newItemPath
     *
     * @return bool
     */
    private function updateRecursive($oldItemPath, $newItemPath)
    {
        $items = $this->findHashesLike($oldItemPath . '%');

        if ($items === null) {
            return false;
        }

        foreach ($items as $item) {
            $item->path = str_replace($oldItemPath, $newItemPath, $item->path);

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
            $size = $meta !== null ? $meta->getSize() : null;
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
        return $this->storage->listContents('/', true);
    }
}
