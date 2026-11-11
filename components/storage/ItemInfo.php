<?php
// file generated with AI assistance: Claude Code - [2026-11-11 20:00:00 UTC]
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2026 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\components\storage;

/**
 * What the storage reports about one item in a single metadata request: type, size and, if the
 * adapter supplies it, the mime type.
 *
 * Returned by Storage::itemInfo() and Storage::fileStat(). Every value is `null` when the adapter
 * does not report it; callers decide on the fallback (they did so before the facade existed, and
 * the fallbacks are part of their pinned behaviour).
 */
class ItemInfo
{
    /**
     * @var string|null
     */
    private $type;

    /**
     * @var int|null
     */
    private $size;

    /**
     * @var string|null
     */
    private $mimeType;

    /**
     * @param string|null $type
     * @param int|null $size
     * @param string|null $mimeType
     */
    public function __construct($type, $size, $mimeType)
    {
        $this->type = $type;
        $this->size = $size;
        $this->mimeType = $mimeType;
    }

    /**
     * `file`, `dir`, or another type the adapter reports (local adapter: `fifo`, `char`, `block`,
     * `socket`, `unknown`; never `link`, see Storage), `null` if not reported
     *
     * @return string|null
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Size in bytes; the local adapter reports it for files only, `null` for directories
     *
     * @return int|null
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * Mime type as reported by the adapter in the same request; the local adapter never reports
     * one here (`null`), some remote adapters do
     *
     * @return string|null
     */
    public function getMimeType()
    {
        return $this->mimeType;
    }
}
