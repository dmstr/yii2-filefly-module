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
 * Class RemoveAccess
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 * @deprecated since 1.9, removed in 2.0: use \hrzg\filefly\components\AccessManager::removeAccess() (FileManager::access())
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
     * @see \hrzg\filefly\components\AccessManager::removeAccess()
     */
    public function handle($itemPath = null, $recursive = false)
    {
        return $this->getAccessManager()->removeAccess($itemPath, $recursive);
    }
}
