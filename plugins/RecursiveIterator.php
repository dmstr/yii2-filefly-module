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
 * Class RecursiveIterator
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class RecursiveIterator extends FilesystemHash implements PluginInterface
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
     * @param string $itemPath
     *
     * @return bool
     */
    public function handle($itemPath = null)
    {
        $find = $itemPath . '%';

        $items = FileflyHashmap::find()
            ->andWhere(['component' => $this->component])
            ->andWhere(['like', 'path', $find, false])
            ->all();

        if (count($items) < 2) {
            return true;
        }

        \Yii::error('Path [' . $itemPath . '] is not empty!', __METHOD__);
        return false;
    }
}
