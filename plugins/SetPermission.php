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
use yii\base\Component;


/**
 * Class SetPermission
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class SetPermission extends Component implements PluginInterface
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
     *
     * @param string $newItemPath
     * @param string $oldItemPath
     *
     * @return bool
     */
    public function handle($oldItemPath = null, $newItemPath = null)
    {
        \Yii::error($oldItemPath, '$oldItemPath.setPerm');

        $oldHash = FileflyHashmap::find()
            ->where(
                [
                    'component'     => $this->component,
                    'path'          => $oldItemPath,
                    'access_domain' => \Yii::$app->language
                ]
            )
            ->one();

        // upload / create
        if (empty($oldHash)) {
            $newHash = new FileflyHashmap(
                [
                    'component'    => $this->component,
                    'path'         => $oldItemPath,
                    'access_owner' => \Yii::$app->user->id
                ]
            );
            if (!$newHash->save()) {
                \Yii::error($newHash->getErrors(), __METHOD__);
                \Yii::error('Could not save new item [' . $oldItemPath . '] to hash table!', __METHOD__);
                return false;
            }
        } else {
            \Yii::error($oldHash->attributes, '$oldHash.setPerm');
            return $this->updateRecursive($oldHash->path, $newItemPath);
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
        $find = $oldItemPath . '%';
        \Yii::error($find, '$find.recursive.setPerm');

        $items = FileflyHashmap::find()
            ->andWhere(['component' => $this->component])
            ->andWhere(['like', 'path', $find, false])
            ->andWhere(['access_domain' => \Yii::$app->language])
            ->all();

        if ($items === null) {
            return false;
        }

        foreach ($items as $item) {
            \Yii::error($item->path, '$item->path.setperm');
            \Yii::error($oldItemPath, '$oldItemPath.setperm');
            \Yii::error(substr($newItemPath, 1), '$newItemPath.setperm');

            $item->path = str_replace($oldItemPath, substr($newItemPath, 1), $item->path);

            if (!$item->save()) {
                \Yii::error($item->getErrors(), 'ERRORS.setPerm');
                \Yii::error('Could not update item [' . $oldItemPath . '] to hash table!', __METHOD__);
                return false;
            }
        }

        return true;
    }
}
