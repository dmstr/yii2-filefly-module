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

/**
 * Class RemoveAccess
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class RemoveAccess extends AccessPlugin
{
    /**
     * @return string
     */
    public function getMethod()
    {
        return 'removeAccess';
    }

    /**
     * The full path strings of the file or directory to be removed
     *
     * @param string $itemPath
     * @param bool $recursive
     *
     * @return bool
     */
    public function handle($itemPath = null, $recursive = false)
    {
        // remove all hashmap entries beneath $itemPath
        if ($recursive) {
            $items = FileflyHashmap::find()
                ->andWhere(['component' => $this->component])
                ->andWhere(['like', 'path', $itemPath . '/%', false])
                ->all();

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

        /** @var $item FileflyHashmap */
        $item = FileflyHashmap::find()
            ->andWhere(['component' => $this->component])
            ->andWhere(['path' => $itemPath])
            ->one();

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
}
