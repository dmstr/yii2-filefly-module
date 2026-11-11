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
 * Class RecursiveIterator
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 * @deprecated since 1.9, removed in 2.0: use \hrzg\filefly\components\AccessManager::isEmpty() (FileManager::access())
 */
class RecursiveIterator extends AccessPlugin
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
     * @param string $path
     *
     * @return bool
     * @see \hrzg\filefly\components\AccessManager::isEmpty()
     */
    public function handle($path)
    {
        return $this->getAccessManager()->isEmpty($path);
    }
}
