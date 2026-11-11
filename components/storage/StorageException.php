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
 * The storage could not perform an operation.
 *
 * Base class of the failures reported by \hrzg\filefly\components\Storage. Thrown as such for
 * every failure the filesystem library reports that has no more specific class below (for
 * example a link the adapter refuses, an unreadable file inside a directory being deleted, a
 * lost connection). The message is the one of the library, the original exception is the
 * previous one.
 *
 * It is a \RuntimeException on purpose: code that catches \RuntimeException around storage
 * calls (the console `fs ls`) keeps catching the adapter failures it caught before.
 */
class StorageException extends \RuntimeException
{
}
