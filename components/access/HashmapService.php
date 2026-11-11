<?php
// file generated with AI assistance: Claude Code - [2026-11-11 18:00:00 UTC]
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2017 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\components\access;

use hrzg\filefly\models\FileflyHashmap;
use yii\base\BaseObject;

/**
 * Common base of the services that work on the hashmap rows of one filesystem component.
 *
 * @internal used by \hrzg\filefly\components\AccessManager, not meant to be used directly
 */
abstract class HashmapService extends BaseObject
{
    /**
     * The yii component name of the filesystem, stored in the `component` column of the hashmap
     * @var string
     */
    public $component;

    /**
     * Ensure exactly one leading forward slash; anything but a string becomes `/`.
     *
     * Trailing slashes and inner segments are kept as given.
     *
     * @param string $path
     *
     * @return string normalized path string
     */
    public static function normalizePath($path)
    {
        if (is_string($path)) {
            return '/' . ltrim($path, '/');
        }
        return '/';
    }

    /**
     * The hashmap row of this component with exactly this path, compared byte by byte.
     *
     * A row the database merely considers equal (under a case-, accent- or trailing-space-insensitive
     * collation, as the MariaDB/MySQL collations of the migrated table are) belongs to a different
     * item and is never returned; see hasConflictingRow().
     *
     * @param string $path
     *
     * @return FileflyHashmap|null
     */
    protected function findHash($path)
    {
        foreach ($this->findHashCandidates($path) as $hash) {
            if ((string)$hash->path === (string)$path) {
                return $hash;
            }
        }
        return null;
    }

    /**
     * Whether the path is taken by a row of a different exact path: a row the database considers
     * equal to $path (`path = :path`) whose stored path is not byte for byte $path. Such a row
     * belongs to another item; a row for $path could not be inserted next to it (unique key on
     * `component`, `path`), and it must not be taken over.
     *
     * Always false on databases that compare byte-wise (sqlite, PostgreSQL).
     *
     * @param string $path the path a row would be created for or moved to (normalized like setAccess())
     * @param string|null $ownPath the path of the item that is being moved to $path: its own row does not count
     *
     * @return bool
     */
    public function hasConflictingRow($path, $ownPath = null)
    {
        $path = static::normalizePath($path);
        $ownPath = $ownPath === null ? null : static::normalizePath($ownPath);
        foreach ($this->findHashCandidates($path) as $hash) {
            $candidate = (string)$hash->path;
            if ($candidate !== $path && $candidate !== $ownPath) {
                return true;
            }
        }
        return false;
    }

    /**
     * The rows of this component the database considers equal to $path; the only query by path
     * equality. Callers decide on exact equality themselves (findHash(), hasConflictingRow()).
     *
     * @param string $path
     *
     * @return FileflyHashmap[]
     */
    private function findHashCandidates($path)
    {
        /** @var FileflyHashmap[] $hashes */
        $hashes = FileflyHashmap::find()
            ->andWhere(['component' => $this->component])
            ->andWhere(['path' => $path])
            ->all();
        return $hashes;
    }

    /**
     * All hashmap rows of this component whose path starts with $prefix, compared byte by byte.
     *
     * The query only narrows the candidates: it matches the longest part of $prefix that contains
     * no LIKE special character (`%`, `_`, `\`) anywhere in the path and, under many MySQL
     * collations, case- and accent-insensitively. The exact, case-sensitive prefix test is done here.
     * The narrowing term needs no escaping, because escaped wildcards are not portable: MySQL with
     * the SQL mode NO_BACKSLASH_ESCAPES has no LIKE escape character, so the backslash escapes Yii
     * adds would make the condition match nothing.
     *
     * @param string $prefix
     *
     * @return FileflyHashmap[]
     */
    protected function findHashesWithPrefix($prefix)
    {
        $prefix = (string)$prefix;
        $query = FileflyHashmap::find()->andWhere(['component' => $this->component]);
        $term = static::likeNarrowingTerm($prefix);
        if ($term !== '') {
            $query->andWhere(['like', 'path', $term]);
        }
        /** @var FileflyHashmap[] $candidates */
        $candidates = $query->all();
        $hashes = [];
        foreach ($candidates as $hash) {
            if (strncmp((string)$hash->path, $prefix, strlen($prefix)) === 0) {
                $hashes[] = $hash;
            }
        }
        return $hashes;
    }

    /**
     * The longest part of $prefix without LIKE special characters (`%`, `_`, `\`); a path that
     * starts with $prefix contains it under every collation. `''` if there is none.
     *
     * @param string $prefix
     *
     * @return string
     */
    protected static function likeNarrowingTerm($prefix)
    {
        $term = '';
        foreach (preg_split('/[%_\\\\]/', (string)$prefix) as $part) {
            if (strlen($part) > strlen($term)) {
                $term = $part;
            }
        }
        return $term;
    }
}
