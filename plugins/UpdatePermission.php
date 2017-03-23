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
use League\Flysystem\PluginInterface;


/**
 * Class UpdatePermission
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class UpdatePermission extends AccessPlugin
{
    /**
     * @return string
     */
    public function getMethod()
    {
        return 'updatePermission';
    }

    /**
     * Build permissions array for multi selects in permission modal
     *
     * @param array $item
     * @param string $path
     *
     * @return bool
     */
    public function handle($item, $path)
    {
        /** @var $hash \hrzg\filefly\models\FileflyHashmap */
        $query = FileflyHashmap::find();
        $query->andWhere(['component' => $this->component]);
        $query->andWhere(['path' => $path]);
        $hash = $query->one();

        if ($hash === null) {
            return false;
        } else {

            $readItems = [];
            foreach ($item['authRead'] as $readItem) {
                $readItems[$readItem['role']] = $readItem['role'];
            }

            $updateItems = [];
            foreach ($item['authUpdate'] as $updateItem) {
                $updateItems[$updateItem['role']] = $updateItem['role'];
            }

            $deleteItems = [];
            foreach ($item['authDelete'] as $deleteItem) {
                $deleteItems[$deleteItem['role']] = $deleteItem['role'];
            }

            // set access permissions
            $hash->authItemArrayToString(Module::ACCESS_READ, $readItems);
            $hash->authItemArrayToString(Module::ACCESS_UPDATE, $updateItems);
            $hash->authItemArrayToString(Module::ACCESS_DELETE, $deleteItems);

            if (!$hash->save()) {
                \Yii::error('Could not update item [' . $path . '] in hash table!', __METHOD__);
                return false;
            }
            return true;
        }
    }
}
