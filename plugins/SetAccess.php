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
     * Create or renew the row of a new item ($oldItemPath alone), or move the rows of a renamed or
     * moved item to $newItemPath
     *
     * @param string $oldItemPath
     * @param string|null $newItemPath
     *
     * @return bool
     * @see \hrzg\filefly\components\AccessManager::setAccess()
     */
    public function handle($oldItemPath = null, $newItemPath = null)
    {
        return $this->getAccessManager()->setAccess($oldItemPath, $newItemPath);
    }
}
