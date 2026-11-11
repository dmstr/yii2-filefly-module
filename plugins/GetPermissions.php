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
 * Class GetPermissions
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 * @deprecated since 1.9, removed in 2.0: use \hrzg\filefly\components\AccessManager::getPermissions() (FileManager::access())
 */
class GetPermissions extends AccessPlugin
{
    /**
     * @return string
     */
    public function getMethod()
    {
        return 'getPermissions';
    }

    /**
     * Build permissions array for multi selects in permission modal
     *
     * @param $path
     *
     * @return array|false
     * @see \hrzg\filefly\components\AccessManager::getPermissions()
     */
    public function handle($path)
    {
        return $this->getAccessManager()->getPermissions($path);
    }
}
