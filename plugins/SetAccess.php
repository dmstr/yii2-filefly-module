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
use League\Flysystem\FileNotFoundException;


/**
 * Class SetAccess
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class SetAccess extends AccessPlugin
{
    /**
     * @return string
     */
    public function getMethod()
    {
        return 'setAccess';
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
        $oldItemPath = $this->normalize($oldItemPath);
        $newItemPath = $this->normalize($newItemPath);

        $oldHash = FileflyHashmap::find()
            ->where(
                [
                    'component' => $this->component,
                    'path' => $oldItemPath,
                ]
            )
            ->one();

        // upload / create
        if (empty($oldHash)) {

            // get meta information
            try {
                $meta = $this->filesystem->getMetadata($oldItemPath);
                $type = (isset($meta['type'])) ? $meta['type'] : null;
                $size = (isset($meta['size'])) ? $meta['size'] : null;
            } catch (FileNotFoundException $e) {
                \Yii::error($e->getMessage(), __METHOD__);
                $type = null;
                $size = null;
            }

            $defaultPermissions = FileflyHashmap::accessDefaults();
            $newHash = new FileflyHashmap(
                [
                    'component' => $this->component,
                    'type' => $type,
                    'path' => $oldItemPath,
                    'size' => $size,
                    'access_owner' => \Yii::$app->user->id,
                    Module::ACCESS_READ => $defaultPermissions[Module::ACCESS_READ],
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
     * @param string $oldItemPath
     * @param string $newItemPath
     *
     * @return bool
     */
    private function updateRecursive($oldItemPath, $newItemPath)
    {
        if (empty($newItemPath)) {
            return true;
        }

        $find = $oldItemPath . '%';

        $items = FileflyHashmap::find()
            ->andWhere(['component' => $this->component])
            ->andWhere(['like', 'path', $find, false])
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
