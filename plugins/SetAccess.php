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

        \Yii::error($oldItemPath, 'setAccess for');
        $oldHash = FileflyHashmap::find()
            ->where(
                [
                    'component' => $this->component,
                    'path'      => $oldItemPath,
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
