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


/**
 * Class SetPermission
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class SetPermission implements PluginInterface
{
    protected $filesystem;

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
        return 'setPermission';
    }

    /**
     * The full path strings of the file or directory to be set or updated
     * @param string $newItemPath
     * @param string $oldItemPath
     *
     * @return bool
     */
    public function handle($oldItemPath = null, $newItemPath = null)
    {
        $oldItemPath = ltrim($oldItemPath, '/');
        $newItemPath = ltrim($newItemPath, '/');

        // find has for item
        $oldHash = FileflyHashmap::find()
            ->where(['path' => $oldItemPath])
            ->one();

        if (empty($oldHash)) {
            $newHash = new FileflyHashmap(['path' => $oldItemPath]);
            if(!$newHash->save()) {
                \Yii::error('Could not save new item [' . $oldItemPath . '] to hash table!', __METHOD__);
                return false;
            }
        } else {
            $oldHash->path = $newItemPath;
            if(!$oldHash->save()) {
                \Yii::error('Could not update item [' . $newItemPath . '] in hash table!', __METHOD__);
                return false;
            }
        }
        return true;
    }
}
