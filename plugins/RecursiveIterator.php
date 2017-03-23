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
use League\Flysystem\PluginInterface;

/**
 * Class RecursiveIterator
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class RecursiveIterator extends AccessPlugin
{
    /**
     * @return string
     */
    public function getMethod()
    {
        return 'isEmpty';
    }

    /**
     * Check sub items from path
     *
     * Query result 1 means no sub items found
     *
     * @param string $path
     *
     * @return bool
     */
    public function handle($path)
    {
        /** @var FileflyHashmap $item */
        $item = FileflyHashmap::find()
            ->andWhere(['component' => $this->component])
            ->andWhere(['path' => $path])
            ->one();

        if (empty($item)) {
            \Yii::info('Path [' . $path . '] not found!', __METHOD__);
            return false;
        }

        // find hashmap items beneath this path
        $find = $item->path . '/%';

        $items = FileflyHashmap::find()
            ->andWhere(['component' => $this->component])
            ->andWhere(['like', 'path', $find, false])
            ->all();

        // if no items found => dir is empty
        if (empty($items)) {
            return true;
        }

        \Yii::info('Path [' . $path . '] is not empty!', __METHOD__);
        return false;
    }
}
