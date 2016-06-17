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
use yii\helpers\StringHelper;


/**
 * Class SetPermission
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class SetPermission implements PluginInterface
{
    protected $filesystem;

    protected $adapterName;

    /**
     * @param FilesystemInterface $filesystem
     */
    public function setFilesystem(FilesystemInterface $filesystem)
    {
        $this->filesystem  = $filesystem;
        $this->adapterName = StringHelper::basename(get_class($filesystem->getAdapter()));
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
     *
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
            ->where(
                [
                    'filesystem' => $this->adapterName,
                    'path'       => $oldItemPath,
                ]
            )
            ->one();

        // upload / create
        if (empty($oldHash)) {
            $newHash = new FileflyHashmap(['filesystem' => $this->adapterName, 'path' => $oldItemPath]);
            if (!$newHash->save()) {
                \Yii::error('Could not save new item [' . $oldItemPath . '] to hash table!', __METHOD__);
                return false;
            }
        } else {
            return $this->updateRecursive($oldItemPath, $newItemPath);
        }

        return true;
    }


    /**
     * @param $oldItemPath
     * @param null $newItemPath
     *
     * @return bool
     */
    private function updateRecursive($oldItemPath, $newItemPath = null)
    {
        $items = FileflyHashmap::find()
            ->andWhere(['filesystem' => $this->adapterName])
            ->andWhere(['like', 'path', $oldItemPath . '%', false])
            ->all();

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
}
