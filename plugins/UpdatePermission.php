<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2017 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\plugins;

/**
 * Class UpdatePermission
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 * @deprecated since 1.9, removed in 2.0: use \hrzg\filefly\components\AccessManager::updatePermission() (FileManager::access())
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
     * @see \hrzg\filefly\components\AccessManager::updatePermission()
     */
    public function handle($item, $path)
    {
        return $this->getAccessManager()->updatePermission($item, $path);
    }
}
