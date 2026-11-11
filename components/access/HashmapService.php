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
     * The hashmap row of this component with exactly this path
     *
     * @param string $path
     *
     * @return FileflyHashmap|null
     */
    protected function findHash($path)
    {
        /** @var FileflyHashmap|null $hash */
        $hash = FileflyHashmap::find()
            ->andWhere(['component' => $this->component])
            ->andWhere(['path' => $path])
            ->one();
        return $hash;
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
