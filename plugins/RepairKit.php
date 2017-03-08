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
use hrzg\filefly\Module;
use League\Flysystem\PluginInterface;


/**
 * Class RepairKit
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class RepairKit extends AccessPlugin
{
    /**
     * @return string
     */
    public function getMethod()
    {
        return 'check';
    }

    /**
     * Check if path has hash and repair with default permissions if not set
     *
     * @param $path
     *
     * @return bool
     */
    public function handle($path)
    {
        // root path do not have the need to check permission
        if ($path === '/') {
            return true;
        }

        // TODO make this configurable, repair true | false
        if (1) {
            $path = $this->normalize($path);

            /** @var $hash \hrzg\filefly\models\FileflyHashmap */
            $query = FileflyHashmap::find();
            $query->andWhere(['component' => $this->component]);
            $query->andWhere(['path' => $path]);
            $hash = $query->one();

            if ($hash === null) {
                $defaultPermissions = FileflyHashmap::accessDefaults();
                $repairHash         = new FileflyHashmap(
                    [
                        'component'           => $this->component,
                        'path'                => $path,
                        Module::ACCESS_OWNER  => $defaultPermissions[Module::ACCESS_OWNER],
                        Module::ACCESS_READ   => $defaultPermissions[Module::ACCESS_READ],
                        Module::ACCESS_UPDATE => $defaultPermissions[Module::ACCESS_UPDATE],
                        Module::ACCESS_DELETE => $defaultPermissions[Module::ACCESS_DELETE],
                    ]
                );
                if (!$repairHash->save()) {
                    \Yii::error('filefly hash could not been repaired for path ' . $path, __METHOD__);
                    return false;
                }
            }
        }
        return true;
    }
}
