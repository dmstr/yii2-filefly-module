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
 * Class SetAccess
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 * @deprecated since 1.9, removed in 2.0: use \hrzg\filefly\components\AccessManager::setAccess() (FileManager::access())
 */
class SetAccess extends AccessPlugin
{
    /**
     * @return string
     */
    public function getMethod()
    {
        return 'setAccess';
    }

    /**
     * The full path strings of the file or directory to be set or updated
     *
     * @param string $oldItemPath
     * @param string $newItemPath
     *
     * @return bool
     * @see \hrzg\filefly\components\AccessManager::setAccess()
     */
    public function handle($oldItemPath = null, $newItemPath = null)
    {
        return $this->getAccessManager()->setAccess($oldItemPath, $newItemPath);
    }
}
