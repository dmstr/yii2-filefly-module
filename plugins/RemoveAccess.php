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
use League\Flysystem\PluginInterface;

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
     *
     * @return bool
     */
    public function handle($itemPath = null)
    {

        $item = FileflyHashmap::find()
            ->andWhere(['component' => $this->component])
            ->andWhere(['path' => $itemPath])
            ->one();

        if ($item === null) {
            \Yii::error('Could not find item [' . $itemPath . '] in hash table!', __METHOD__);
            return false;
        }
        if (!$item->delete()) {
            \Yii::error('Could not delete item [' . $itemPath . '] in hash table!', __METHOD__);
            return false;
        }

        return true;
    }
}
