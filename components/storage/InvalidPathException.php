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
 * A path the storage refuses before any request: it leaves the storage root (`..` above the
 * root), contains control or invalid unicode characters, or names the root where the root is not
 * allowed (deleting it).
 *
 * It is a \LogicException (a caller error, not a storage failure) and deliberately no
 * StorageException: handlers catching storage failures or \RuntimeException do not catch it,
 * exactly like the \LogicException that Flysystem 1 throws for these paths.
 */
class InvalidPathException extends \InvalidArgumentException
{
}
