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
 * Class GrantAccess
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 * @deprecated since 1.9, removed in 2.0: use \hrzg\filefly\components\AccessManager::grantAccess() (FileManager::access())
 */
class GrantAccess extends AccessPlugin
{
    /**
     * @return string
     */
    public function getMethod()
    {
        return 'grantAccess';
    }

    /**
     * Find permissions for paths by permission type
     *
     * @param string $path
     * @param string $permissionType
     * @param bool $checkParent
     *
     * @return bool|null
     * @see \hrzg\filefly\components\AccessManager::grantAccess()
     */
    public function handle($path, $permissionType = 'access_read', $checkParent = true)
    {
        return $this->getAccessManager()->grantAccess($path, $permissionType, $checkParent);
    }
}
