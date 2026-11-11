<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2017 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\plugins;

use hrzg\filefly\components\access\HashmapSynchronizer;

/**
 * Class SelfHealKit
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 * @deprecated since 1.9, removed in 2.0: use \hrzg\filefly\components\AccessManager::check() (FileManager::access())
 */
class SelfHealKit extends AccessPlugin
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
     * @param string $path
     * @param bool $repair
     * @param bool $all sync filesystem with database
     *
     * @return bool
     * @see \hrzg\filefly\components\AccessManager::check()
     */
    public function handle($path, $repair, $all = false)
    {
        return $this->getAccessManager()->check($path, $repair, $all);
    }

    /**
     * ensure one beginning forward slash
     *
     * @param string $path
     *
     * @return string normalized path string
     */
    protected function normalize($path)
    {
        return HashmapSynchronizer::normalizeRepairPath($path);
    }
}
