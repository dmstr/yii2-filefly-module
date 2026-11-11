<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\support;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

/**
 * The complete content of a filesystem as seen through its Flysystem operator, independent of
 * the facade under test: files as `path => content`, directories as `path/ => null`. A directory
 * is every directory entry of a deep listing plus every parent of an entry, so an S3 prefix with
 * objects below it is a directory, and a file and a directory of the same name are two keys.
 */
final class RawTree
{
    public static function of(FilesystemOperator $operator): array
    {
        $tree = [];
        /** @var StorageAttributes $attributes */
        foreach ($operator->listContents('', true) as $attributes) {
            $path = trim($attributes->path(), '/');
            if ($attributes->isDir()) {
                $tree[$path . '/'] = null;
            } else {
                $tree[$path] = $operator->read($path);
            }
            for ($parent = dirname($path); $parent !== '.' && $parent !== ''; $parent = dirname($parent)) {
                $tree[$parent . '/'] = null;
            }
        }
        ksort($tree, SORT_STRING);
        return $tree;
    }
}
