<?php
// file generated with AI assistance: Claude Code - [2026-11-11 21:00:00 UTC]

declare(strict_types=1);

namespace hrzg\filefly\adapterprobe;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use ReflectionProperty;
use Throwable;

/**
 * The scripted scenario. Every row runs in its own directory (Ctx::$dir) and
 * records what happened; nothing is asserted.
 */
final class Scenarios
{
    /** 1x1 transparent PNG */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>';

    /**
     * @return list<Scenario>
     */
    public static function all(): array
    {
        return array_merge(
            self::writes(),
            self::existence(),
            self::createDirectory(),
            self::moveAndCopy('move'),
            self::moveAndCopy('copy'),
            self::deletes(),
            self::listings(),
            self::metadata(),
            self::mimeTypes(),
            self::reads(),
            self::paths(),
            self::local(),
            self::s3Requests(),
            self::extras(),
        );
    }

    private static function row(string $id, string $group, string $title, callable $run, ?array $kinds = null): Scenario
    {
        return new Scenario($id, $group, $title, \Closure::fromCallable($run), $kinds);
    }

    /**
     * @return list<Scenario>
     */
    private static function writes(): array
    {
        $g = 'write';

        return [
            self::row('write.new', $g, 'write a new file', fn (Ctx $c) => [
                'write' => $c->attempt(fn () => $c->fs->write($c->p('a.txt'), 'one')),
                'read' => $c->read($c->p('a.txt')),
            ]),
            self::row('write.existing', $g, 'write onto an existing file', function (Ctx $c) {
                $c->fs->write($c->p('a.txt'), 'old');

                return [
                    'write' => $c->attempt(fn () => $c->fs->write($c->p('a.txt'), 'new')),
                    'read' => $c->read($c->p('a.txt')),
                ];
            }),
            self::row('writeStream.existing', $g, 'writeStream onto an existing file', function (Ctx $c) {
                $c->fs->write($c->p('a.txt'), 'old');

                return [
                    'writeStream' => $c->attempt(fn () => $c->fs->writeStream($c->p('a.txt'), self::stream('new'))),
                    'read' => $c->read($c->p('a.txt')),
                ];
            }),
            self::row('write.onto-directory', $g, 'write where a directory of that name exists', function (Ctx $c) {
                $c->fs->createDirectory($c->p('d'));
                $c->fs->write($c->p('d/child.txt'), 'child');

                return [
                    'write' => $c->attempt(fn () => $c->fs->write($c->p('d'), 'file')),
                    'exists(d)' => $c->exists($c->p('d')),
                    'read(d)' => $c->read($c->p('d')),
                    'child' => $c->read($c->p('d/child.txt')),
                    'list' => $c->tree(),
                    'raw keys' => $c->rawKeys(),
                ];
            }),
            self::row('write.below-file', $g, 'write below a path whose parent is a file', function (Ctx $c) {
                $c->fs->write($c->p('f.txt'), 'file');

                return [
                    'write' => $c->attempt(fn () => $c->fs->write($c->p('f.txt/child.txt'), 'child')),
                    'exists(f.txt)' => $c->exists($c->p('f.txt')),
                    'read(f.txt)' => $c->read($c->p('f.txt')),
                    'read(child)' => $c->read($c->p('f.txt/child.txt')),
                    'list' => $c->tree(),
                ];
            }),
            self::row('write.missing-parents', $g, 'write into a missing nested directory', fn (Ctx $c) => [
                'write' => $c->attempt(fn () => $c->fs->write($c->p('x/y/z/file.txt'), 'deep')),
                'exists(x)' => $c->exists($c->p('x')),
                'exists(x/y)' => $c->exists($c->p('x/y')),
                'list shallow' => $c->tree('', false),
                'list deep' => $c->tree(),
                'raw keys' => $c->rawKeys(),
            ]),
        ];
    }

    /**
     * @return list<Scenario>
     */
    private static function existence(): array
    {
        $g = 'fileExists / directoryExists / has';
        $setup = static function (Ctx $c): void {
            $c->fs->write($c->p('file.txt'), 'x');
            $c->fs->createDirectory($c->p('explicit'));
            $c->fs->write($c->p('explicit/child.txt'), 'x');
            $c->fs->write($c->p('implicit/child.txt'), 'x');
            $c->fs->createDirectory($c->p('empty'));
        };
        $rows = [];
        foreach ([
            'file' => ['file.txt', 'a file'],
            'dir-explicit' => ['explicit', 'a directory created with createDirectory (with a child)'],
            'dir-implicit' => ['implicit', 'a directory that exists only implicitly (file below it, no createDirectory)'],
            'dir-empty' => ['empty', 'an empty directory'],
            'dir-trailing-slash' => ['explicit/', 'a directory, path with trailing slash'],
            'missing' => ['missing', 'a missing path'],
        ] as $id => [$relative, $title]) {
            $rows[] = self::row('exists.' . $id, $g, $title, function (Ctx $c) use ($setup, $relative) {
                $setup($c);

                return ['result' => $c->exists($c->p($relative))];
            });
        }
        $rows[] = self::row('exists.root', $g, 'the root (`\'\'` and `/`) of a component rooted at a directory', function (Ctx $c) {
            $c->fs->write($c->p('r/file.txt'), 'x');
            $rooted = $c->rooted('r');

            return [
                "''" => $c->exists('', $rooted),
                "'/'" => $c->exists('/', $rooted),
            ];
        });
        $rows[] = self::row('exists.root-empty', $g, 'the root of a component rooted at an EMPTY directory', function (Ctx $c) {
            $rooted = $c->rooted('r');

            return ["''" => $c->exists('', $rooted)];
        });

        return $rows;
    }

    /**
     * @return list<Scenario>
     */
    private static function createDirectory(): array
    {
        $g = 'createDirectory';

        return [
            self::row('mkdir.new', $g, 'new directory', fn (Ctx $c) => [
                'createDirectory' => $c->attempt(fn () => $c->fs->createDirectory($c->p('new'))),
                'exists' => $c->exists($c->p('new')),
                'list' => $c->tree(),
                'raw keys' => $c->rawKeys(),
            ]),
            self::row('mkdir.nested', $g, 'nested directory a/b/c in one call', fn (Ctx $c) => [
                'createDirectory' => $c->attempt(fn () => $c->fs->createDirectory($c->p('a/b/c'))),
                'exists(a)' => $c->exists($c->p('a')),
                'exists(a/b/c)' => $c->exists($c->p('a/b/c')),
                'list deep' => $c->tree(),
                'raw keys' => $c->rawKeys(),
            ]),
            self::row('mkdir.existing', $g, 'directory that already exists', function (Ctx $c) {
                $c->fs->createDirectory($c->p('d'));
                $c->fs->write($c->p('d/keep.txt'), 'keep');

                return [
                    'createDirectory' => $c->attempt(fn () => $c->fs->createDirectory($c->p('d'))),
                    'child' => $c->read($c->p('d/keep.txt')),
                    'raw keys' => $c->rawKeys(),
                ];
            }),
            self::row('mkdir.onto-file', $g, 'where a file of that name exists', function (Ctx $c) {
                $c->fs->write($c->p('f'), 'file');

                return [
                    'createDirectory' => $c->attempt(fn () => $c->fs->createDirectory($c->p('f'))),
                    'exists(f)' => $c->exists($c->p('f')),
                    'read(f)' => $c->read($c->p('f')),
                    'list' => $c->tree(),
                    'raw keys' => $c->rawKeys(),
                ];
            }),
        ];
    }

    /**
     * @return list<Scenario>
     */
    private static function moveAndCopy(string $op): array
    {
        $g = $op;
        $run = static fn (Ctx $c, string $from, string $to, ?FilesystemOperator $fs = null) => $c->attempt(
            fn () => ($fs ?? $c->fs)->{$op}($from, $to)
        );

        return [
            self::row("$op.to-missing", $g, 'file onto a missing target', function (Ctx $c) use ($run) {
                $c->fs->write($c->p('src.txt'), 'SRC');

                return [
                    'result' => $run($c, $c->p('src.txt'), $c->p('dst.txt')),
                    'dst' => $c->read($c->p('dst.txt')),
                    'src' => $c->read($c->p('src.txt')),
                ];
            }),
            self::row("$op.onto-file", $g, 'file onto an existing FILE', function (Ctx $c) use ($run) {
                $c->fs->write($c->p('src.txt'), 'SRC');
                $c->fs->write($c->p('dst.txt'), 'DST');

                return [
                    'result' => $run($c, $c->p('src.txt'), $c->p('dst.txt')),
                    'dst' => $c->read($c->p('dst.txt')),
                    'src' => $c->read($c->p('src.txt')),
                ];
            }),
            self::row("$op.onto-directory", $g, 'file onto an existing directory (with a child)', function (Ctx $c) use ($run) {
                $c->fs->write($c->p('src.txt'), 'SRC');
                $c->fs->createDirectory($c->p('dst'));
                $c->fs->write($c->p('dst/child.txt'), 'CHILD');

                return [
                    'result' => $run($c, $c->p('src.txt'), $c->p('dst')),
                    'exists(dst)' => $c->exists($c->p('dst')),
                    'read(dst)' => $c->read($c->p('dst')),
                    'child' => $c->read($c->p('dst/child.txt')),
                    'src' => $c->read($c->p('src.txt')),
                    'list deep' => $c->tree(),
                ];
            }),
            self::row("$op.onto-itself", $g, 'file onto itself', function (Ctx $c) use ($run) {
                $c->fs->write($c->p('src.txt'), 'SRC');

                return [
                    'result' => $run($c, $c->p('src.txt'), $c->p('src.txt')),
                    'src' => $c->read($c->p('src.txt')),
                ];
            }),
            self::row("$op.missing-source", $g, 'missing source', fn (Ctx $c) => [
                'result' => $run($c, $c->p('nope.txt'), $c->p('dst.txt')),
                'exists(dst)' => $c->exists($c->p('dst.txt')),
            ]),
            self::row("$op.directory-source", $g, 'directory (with children) as source', function (Ctx $c) use ($run) {
                $c->fs->createDirectory($c->p('srcdir'));
                $c->fs->write($c->p('srcdir/a.txt'), 'A');
                $c->fs->write($c->p('srcdir/sub/b.txt'), 'B');

                return [
                    'result' => $run($c, $c->p('srcdir'), $c->p('dstdir')),
                    'exists(srcdir)' => $c->exists($c->p('srcdir')),
                    'exists(dstdir)' => $c->exists($c->p('dstdir')),
                    'list deep' => $c->tree(),
                ];
            }),
            self::row("$op.directory-onto-directory", $g, 'directory onto an existing directory', function (Ctx $c) use ($run) {
                $c->fs->write($c->p('srcdir/a.txt'), 'A');
                $c->fs->write($c->p('dstdir/old.txt'), 'OLD');

                return [
                    'result' => $run($c, $c->p('srcdir'), $c->p('dstdir')),
                    'list deep' => $c->tree(),
                ];
            }),
            self::row("$op.into-missing-nested", $g, 'file into a missing nested directory', function (Ctx $c) use ($run) {
                $c->fs->write($c->p('src.txt'), 'SRC');

                return [
                    'result' => $run($c, $c->p('src.txt'), $c->p('n1/n2/dst.txt')),
                    'dst' => $c->read($c->p('n1/n2/dst.txt')),
                    'exists(n1)' => $c->exists($c->p('n1')),
                    'list deep' => $c->tree(),
                ];
            }),
            self::row("$op.into-root", $g, 'file from a subdirectory into the root (component rooted at the scenario dir)', function (Ctx $c) use ($run) {
                $c->fs->write($c->p('r/sub/src.txt'), 'SRC');
                $rooted = $c->rooted('r');

                return [
                    'result' => $run($c, 'sub/src.txt', 'src.txt', $rooted),
                    'root list' => $c->tree('', true, $rooted),
                ];
            }),
        ];
    }

    /**
     * @return list<Scenario>
     */
    private static function deletes(): array
    {
        $g = 'delete / deleteDirectory';

        return [
            self::row('delete.file', $g, 'delete a file', function (Ctx $c) {
                $c->fs->write($c->p('a.txt'), 'x');

                return ['delete' => $c->attempt(fn () => $c->fs->delete($c->p('a.txt'))), 'exists' => $c->exists($c->p('a.txt'))];
            }),
            self::row('delete.missing', $g, 'delete a missing path', fn (Ctx $c) => [
                'delete' => $c->attempt(fn () => $c->fs->delete($c->p('nope.txt'))),
            ]),
            self::row('delete.directory', $g, 'delete() on a directory with a child', function (Ctx $c) {
                $c->fs->createDirectory($c->p('d'));
                $c->fs->write($c->p('d/child.txt'), 'x');

                return [
                    'delete' => $c->attempt(fn () => $c->fs->delete($c->p('d'))),
                    'exists(d)' => $c->exists($c->p('d')),
                    'child' => $c->read($c->p('d/child.txt')),
                ];
            }),
            self::row('deleteDirectory.empty', $g, 'deleteDirectory of an empty directory', function (Ctx $c) {
                $c->fs->createDirectory($c->p('d'));

                return [
                    'deleteDirectory' => $c->attempt(fn () => $c->fs->deleteDirectory($c->p('d'))),
                    'exists(d)' => $c->exists($c->p('d')),
                    'raw keys' => $c->rawKeys(),
                ];
            }),
            self::row('deleteDirectory.with-children', $g, 'deleteDirectory of a directory with children', function (Ctx $c) {
                $c->fs->createDirectory($c->p('d'));
                $c->fs->write($c->p('d/a.txt'), 'x');
                $c->fs->write($c->p('d/sub/b.txt'), 'x');

                return [
                    'deleteDirectory' => $c->attempt(fn () => $c->fs->deleteDirectory($c->p('d'))),
                    'exists(d)' => $c->exists($c->p('d')),
                    'list' => $c->tree(),
                ];
            }),
            self::row('deleteDirectory.implicit', $g, 'deleteDirectory of an implicit directory (only a file below it)', function (Ctx $c) {
                $c->fs->write($c->p('d/a.txt'), 'x');

                return [
                    'deleteDirectory' => $c->attempt(fn () => $c->fs->deleteDirectory($c->p('d'))),
                    'exists(d)' => $c->exists($c->p('d')),
                ];
            }),
            self::row('deleteDirectory.missing', $g, 'deleteDirectory of a missing directory', fn (Ctx $c) => [
                'deleteDirectory' => $c->attempt(fn () => $c->fs->deleteDirectory($c->p('nope'))),
            ]),
            self::row('deleteDirectory.file', $g, 'deleteDirectory on a file', function (Ctx $c) {
                $c->fs->write($c->p('f.txt'), 'x');

                return [
                    'deleteDirectory' => $c->attempt(fn () => $c->fs->deleteDirectory($c->p('f.txt'))),
                    'exists(f.txt)' => $c->exists($c->p('f.txt')),
                ];
            }),
            self::row('deleteDirectory.prefix-sibling', $g, 'deleteDirectory(d) when a sibling d-other/ and file d.txt exist', function (Ctx $c) {
                $c->fs->write($c->p('d/a.txt'), 'x');
                $c->fs->write($c->p('d-other/b.txt'), 'x');
                $c->fs->write($c->p('d.txt'), 'x');

                return [
                    'deleteDirectory' => $c->attempt(fn () => $c->fs->deleteDirectory($c->p('d'))),
                    'list deep' => $c->tree(),
                ];
            }),
            self::row('deleteDirectory.root', $g, "deleteDirectory('') on a component rooted at a directory", function (Ctx $c) {
                $c->fs->write($c->p('r/a.txt'), 'x');
                $c->fs->write($c->p('r/sub/b.txt'), 'x');
                $rooted = $c->rooted('r');

                return [
                    'deleteDirectory' => $c->attempt(fn () => $rooted->deleteDirectory('')),
                    'scenario dir after' => $c->tree(),
                    'exists(r)' => $c->exists($c->p('r')),
                ];
            }),
        ];
    }

    /**
     * @return list<Scenario>
     */
    private static function listings(): array
    {
        $g = 'listContents';
        $setup = static function (Ctx $c): void {
            $c->fs->write($c->p('list/b.txt'), 'bbb');
            $c->fs->write($c->p('list/a.png'), base64_decode(self::PNG));
            $c->fs->createDirectory($c->p('list/sub'));
            $c->fs->write($c->p('list/sub/c.txt'), 'c');
            $c->fs->write($c->p('list/implicit/d.txt'), 'd');
            $c->fs->createDirectory($c->p('list/empty'));
        };
        $attributes = static function (Ctx $c, string $base, bool $deep, ?FilesystemOperator $fs = null): array {
            $fs ??= $c->fs;
            try {
                $file = $dir = null;
                $sorted = [];
                foreach ($fs->listContents($base, $deep) as $item) {
                    $sorted[] = $item->path();
                    if ($item->isFile() && $file === null) {
                        $file = $item;
                    }
                    if ($item->isDir() && $dir === null) {
                        $dir = $item;
                    }
                }
                $ordered = $sorted;
                sort($ordered, SORT_STRING);

                return [
                    'order' => $ordered === $sorted ? 'sorted' : 'unsorted',
                    'first file entry' => $file ? Ctx::attributes($file) : '-',
                    'first dir entry' => $dir ? Ctx::attributes($dir) : '-',
                ];
            } catch (Throwable $exception) {
                return ['attributes' => Ctx::describeException($exception)];
            }
        };

        return [
            self::row('list.dir.shallow', $g, 'shallow listing of a directory with files, subdirectories, an implicit and an empty directory', function (Ctx $c) use ($setup, $attributes) {
                $setup($c);

                return ['entries' => $c->tree($c->p('list'), false)] + $attributes($c, $c->p('list'), false);
            }),
            self::row('list.dir.deep', $g, 'deep listing of the same directory', function (Ctx $c) use ($setup, $attributes) {
                $setup($c);

                return ['entries' => $c->tree($c->p('list'), true)] + $attributes($c, $c->p('list'), true);
            }),
            self::row('list.root.shallow', $g, "shallow listing of the root ('') of a component rooted at the directory", function (Ctx $c) use ($setup) {
                $setup($c);
                $rooted = $c->rooted('list');

                return [
                    "''" => self::rawTree($rooted, '', false),
                    "'/'" => self::rawTree($rooted, '/', false),
                ];
            }),
            self::row('list.root.deep', $g, "deep listing of the root ('') of a component rooted at the directory", function (Ctx $c) use ($setup) {
                $setup($c);

                return ['entries' => self::rawTree($c->rooted('list'), '', true)];
            }),
            self::row('list.path-form', $g, 'raw path of entries when listing `dir`, `/dir`, `dir/`', function (Ctx $c) {
                $c->fs->write($c->p('pf/a.txt'), 'x');
                $c->fs->createDirectory($c->p('pf/sub'));

                return [
                    'dir' => self::rawTree($c->fs, $c->p('pf'), false),
                    '/dir' => self::rawTree($c->fs, '/' . $c->p('pf'), false),
                    'dir/' => self::rawTree($c->fs, $c->p('pf') . '/', false),
                ];
            }),
            self::row('list.empty', $g, 'an empty directory', function (Ctx $c) {
                $c->fs->createDirectory($c->p('empty'));

                return ['shallow' => $c->tree($c->p('empty'), false), 'deep' => $c->tree($c->p('empty'), true)];
            }),
            self::row('list.missing', $g, 'a missing path', fn (Ctx $c) => [
                'shallow' => $c->tree($c->p('nope'), false),
                'deep' => $c->tree($c->p('nope'), true),
            ]),
            self::row('list.file', $g, 'a path that is a file', function (Ctx $c) {
                $c->fs->write($c->p('f.txt'), 'x');

                return ['shallow' => $c->tree($c->p('f.txt'), false), 'deep' => $c->tree($c->p('f.txt'), true)];
            }),
            self::row('list.ftp-timestamps', $g, 'FTP: last_modified in listings with and without timestampsOnUnixListingsEnabled, versus lastModified() (MDTM)', function (Ctx $c) {
                $c->fs->write($c->p('t.txt'), 'x');
                $without = $c->backend->build(['timestampsOnUnixListingsEnabled' => false])->getFilesystem();
                $first = static function (FilesystemOperator $fs, string $dir): string {
                    try {
                        foreach ($fs->listContents($dir, false) as $item) {
                            if ($item->isFile()) {
                                return Ctx::timestamp($item->lastModified());
                            }
                        }

                        return 'no file entry';
                    } catch (Throwable $exception) {
                        return Ctx::describeException($exception);
                    }
                };

                return [
                    'listing, option true' => $first($c->fs, $c->dir),
                    'listing, option false' => $first($without, $c->dir),
                    'lastModified()' => $c->attempt(fn () => Ctx::timestamp($c->fs->lastModified($c->p('t.txt')))),
                ];
            }, [Backend::KIND_FTP]),
        ];
    }

    private static function rawTree(FilesystemOperator $fs, string $path, bool $deep): string
    {
        try {
            $entries = [];
            foreach ($fs->listContents($path, $deep) as $item) {
                $entries[] = ($item->isDir() ? 'd:' : 'f:') . "'" . Ctx::markDir($item->path()) . "'";
            }

            return $entries === [] ? '(empty)' : implode(' ', $entries);
        } catch (Throwable $exception) {
            return Ctx::describeException($exception);
        }
    }

    /**
     * @return list<Scenario>
     */
    private static function metadata(): array
    {
        $g = 'metadata';
        $all = static function (Ctx $c, string $path): array {
            return [
                'fileSize' => $c->attempt(fn () => $c->fs->fileSize($path)),
                'lastModified' => $c->attempt(fn () => Ctx::timestamp($c->fs->lastModified($path))),
                'mimeType' => $c->attempt(fn () => $c->fs->mimeType($path)),
                'visibility' => $c->attempt(fn () => $c->fs->visibility($path)),
            ];
        };

        return [
            self::row('meta.file', $g, 'a 5 byte text file written just now', function (Ctx $c) use ($all) {
                $c->fs->write($c->p('a.txt'), 'hello');

                return $all($c, $c->p('a.txt'));
            }),
            self::row('meta.directory', $g, 'a directory', function (Ctx $c) use ($all) {
                $c->fs->createDirectory($c->p('d'));
                $c->fs->write($c->p('d/a.txt'), 'x');

                return $all($c, $c->p('d'));
            }),
            self::row('meta.missing', $g, 'a missing path', fn (Ctx $c) => $all($c, $c->p('nope.txt'))),
            self::row('meta.setVisibility', $g, "setVisibility('public') then visibility(), and write with visibility=public", function (Ctx $c) {
                $c->fs->write($c->p('a.txt'), 'x');

                return [
                    'setVisibility' => $c->attempt(fn () => $c->fs->setVisibility($c->p('a.txt'), 'public')),
                    'visibility' => $c->attempt(fn () => $c->fs->visibility($c->p('a.txt'))),
                    'write public' => $c->attempt(fn () => $c->fs->write($c->p('b.txt'), 'x', ['visibility' => 'public'])),
                    'visibility(b)' => $c->attempt(fn () => $c->fs->visibility($c->p('b.txt'))),
                ];
            }),
        ];
    }

    /**
     * @return list<Scenario>
     */
    private static function mimeTypes(): array
    {
        $g = 'mimeType';
        $rows = [];
        foreach ([
            'text' => ['a.txt', 'hello world', 'text file a.txt'],
            'png' => ['a.png', base64_decode(self::PNG), 'PNG a.png'],
            'png-no-extension' => ['image', base64_decode(self::PNG), 'PNG content, no extension'],
            'png-as-txt' => ['image.txt', base64_decode(self::PNG), 'PNG content named image.txt (content or extension?)'],
            'text-as-png' => ['fake.png', 'just text', 'text content named fake.png'],
            'svg' => ['a.svg', self::SVG, 'SVG a.svg'],
            'no-extension' => ['README', 'plain text', 'text file without extension'],
            'empty-txt' => ['empty.txt', '', 'empty file empty.txt'],
            'empty-no-extension' => ['empty', '', 'empty file without extension'],
        ] as $id => [$name, $content, $title]) {
            $rows[] = self::row('mime.' . $id, $g, $title, function (Ctx $c) use ($name, $content) {
                $c->fs->write($c->p($name), $content);

                return ['mimeType' => $c->attempt(fn () => $c->fs->mimeType($c->p($name)))];
            });
        }

        return $rows;
    }

    /**
     * @return list<Scenario>
     */
    private static function reads(): array
    {
        $g = 'readStream';

        return [
            self::row('readStream.file', $g, 'readStream of a file', function (Ctx $c) {
                $c->fs->write($c->p('a.txt'), 'content');

                return ['readStream' => $c->attempt(fn () => stream_get_contents($c->fs->readStream($c->p('a.txt'))))];
            }),
            self::row('readStream.missing', $g, 'readStream of a missing path', fn (Ctx $c) => [
                'readStream' => $c->attempt(fn () => $c->fs->readStream($c->p('nope.txt'))),
                'read' => $c->read($c->p('nope.txt')),
            ]),
            self::row('readStream.directory', $g, 'readStream of a directory', function (Ctx $c) {
                $c->fs->createDirectory($c->p('d'));
                $c->fs->write($c->p('d/a.txt'), 'x');

                return [
                    'readStream' => $c->attempt(fn () => self::drain($c->fs->readStream($c->p('d')))),
                    'read' => $c->read($c->p('d')),
                ];
            }),
            self::row('roundtrip.5mb', $g, '5 MB random file: writeStream, fileSize, readStream, sha256', function (Ctx $c) {
                $data = random_bytes(5 * 1024 * 1024);
                $start = microtime(true);
                $write = $c->attempt(fn () => $c->fs->writeStream($c->p('big.bin'), self::stream($data)));
                $written = microtime(true);
                $read = $c->attempt(function () use ($c, $data) {
                    $stream = $c->fs->readStream($c->p('big.bin'));
                    $hash = hash_init('sha256');
                    hash_update_stream($hash, $stream);

                    return hash_final($hash) === hash('sha256', $data) ? 'sha256 equal' : 'sha256 DIFFERENT';
                });

                return [
                    'writeStream' => $write . sprintf(' (%d ms)', (int) (($written - $start) * 1000)),
                    'fileSize' => $c->attempt(fn () => $c->fs->fileSize($c->p('big.bin'))),
                    'readStream' => $read . sprintf(' (%d ms)', (int) ((microtime(true) - $written) * 1000)),
                ];
            }),
        ];
    }

    /**
     * @return list<Scenario>
     */
    private static function paths(): array
    {
        $g = 'path handling';
        $rows = [];
        $variants = [
            'leading-slash' => ['/{dir}/a.txt', 'leading slash'],
            'trailing-slash' => ['{dir}/a.txt/', 'trailing slash on a file path'],
            'double-slash' => ['{dir}//a.txt', 'double slash'],
            'dot' => ['{dir}/./a.txt', '`.` segment'],
            'dotdot-inside' => ['{dir}/x/../a.txt', '`..` segment that stays inside the root'],
            'dotdot-outside' => ['../a.txt', '`..` leaving the root'],
            'dotdot-outside-deep' => ['{dir}/../../a.txt', '`..` leaving the root from a subdirectory'],
            'backslash' => ['{dir}\\a.txt', 'backslash as separator'],
            'backslash-in-subdir' => ['{dir}/sub\\a.txt', 'backslash inside a name below a subdirectory'],
            'nul' => ["{dir}/a\0b.txt", 'NUL byte'],
            'control' => ["{dir}/a\x01b.txt", 'control character 0x01'],
            'newline' => ["{dir}/a\nb.txt", 'newline'],
            'space' => ['{dir}/with space.txt', 'space'],
            'spaces-edge' => ['{dir}/ edge .txt', 'leading and trailing space in the name'],
            'umlaut' => ['{dir}/Grüße äöü ß.txt', 'umlauts (NFC)'],
            'umlaut-nfd' => ["{dir}/Gru\u{0308}ße.txt", 'umlaut in NFD (u + combining diaeresis)'],
            'hash' => ['{dir}/#hash.txt', '`#`'],
            'percent' => ['{dir}/100% sure %20.txt', '`%` and a literal `%20`'],
            'plus' => ['{dir}/a+b.txt', '`+`'],
            'question-star' => ['{dir}/what?[x]*.txt', '`?`, `[`, `]`, `*`'],
            'long-255' => ['{dir}/' . str_repeat('x', 251) . '.txt', 'name of 255 bytes'],
            'long-300' => ['{dir}/' . str_repeat('y', 296) . '.txt', 'name of 300 bytes'],
        ];
        foreach ($variants as $id => [$template, $title]) {
            $rows[] = self::row('path.' . $id, $g, $title, function (Ctx $c) use ($template) {
                $path = str_replace('{dir}', $c->dir, $template);
                $write = $c->attempt(fn () => $c->fs->write($path, 'content'));
                $listed = [];
                try {
                    foreach ($c->fs->listContents($c->dir, true) as $item) {
                        $listed[] = basename($item->path());
                    }
                } catch (Throwable) {
                }
                $name = basename(str_replace('\\', '/', $path));

                return [
                    'write' => $write,
                    'listed name' => $listed === [] ? '-' : (in_array($name, $listed, true) ? 'same bytes as written' : 'DIFFERENT bytes: ' . bin2hex($listed[0])),
                    'read same path' => $c->read($path),
                    'fileExists' => $c->attempt(fn () => $c->fs->fileExists($path)),
                    'landed at' => $c->tree(),
                ];
            });
        }
        $rows[] = self::row('path.directory-forms', $g, 'directoryExists / listContents with `dir`, `dir/`, `/dir`, `/dir/`, `dir//`', function (Ctx $c) {
            $c->fs->write($c->p('d/a.txt'), 'x');
            $out = [];
            foreach (['{d}', '{d}/', '/{d}', '/{d}/', '{d}//'] as $form) {
                $path = str_replace('{d}', $c->p('d'), $form);
                $out[str_replace('{d}', 'd', $form)] = 'de=' . $c->attempt(fn () => $c->fs->directoryExists($path))
                    . ' list=' . $c->tree($path, false);
            }

            return $out;
        });

        return $rows;
    }

    /**
     * @return list<Scenario>
     */
    private static function local(): array
    {
        $g = 'local only: symlinks and permissions';
        $links = static function (Ctx $c, ?int $linkHandling): array {
            $c->fs->write($c->p('target.txt'), 'TARGET');
            $c->fs->write($c->p('targetdir/inner.txt'), 'INNER');
            symlink($c->localDiskPath($c->p('target.txt')), $c->localDiskPath($c->p('link.txt')));
            symlink($c->localDiskPath($c->p('targetdir')), $c->localDiskPath($c->p('linkdir')));
            $fs = $linkHandling === null ? $c->fs : $c->backend->build(['linkHandling' => $linkHandling])->getFilesystem();

            return [
                'list shallow' => $c->tree('', false, $fs),
                'list deep' => $c->tree('', true, $fs),
                'exists(link.txt)' => $c->exists($c->p('link.txt'), $fs),
                'read(link.txt)' => $c->read($c->p('link.txt'), $fs),
                'fileSize(link.txt)' => $c->attempt(fn () => $fs->fileSize($c->p('link.txt'))),
                'read(linkdir/inner.txt)' => $c->read($c->p('linkdir/inner.txt'), $fs),
                'exists(linkdir)' => $c->exists($c->p('linkdir'), $fs),
                'delete(link.txt)' => $c->attempt(fn () => $fs->delete($c->p('link.txt'))) . ', target ' . $c->read($c->p('target.txt')),
                'deleteDirectory(linkdir)' => $c->attempt(fn () => $fs->deleteDirectory($c->p('linkdir'))),
                'targetdir after' => $c->tree($c->p('targetdir')) . ', inner.txt ' . $c->read($c->p('targetdir/inner.txt')),
                'linkdir after' => is_link($c->localDiskPath($c->p('linkdir'))) ? 'symlink still there' : 'symlink gone',
            ];
        };
        $mode = static fn (Ctx $c, string $path): string => sprintf('%04o', fileperms($c->localDiskPath($path)) & 0777);

        return [
            self::row('local.symlinks.default', $g, 'symlink to a file and to a directory, creocoder default linkHandling (DISALLOW_LINKS)', fn (Ctx $c) => $links($c, null), [Backend::KIND_LOCAL]),
            self::row('local.symlinks.skip', $g, 'the same with linkHandling SKIP_LINKS', fn (Ctx $c) => $links($c, LocalFilesystemAdapter::SKIP_LINKS), [Backend::KIND_LOCAL]),
            self::row('local.symlink-outside-root', $g, 'symlink to a directory OUTSIDE the root: read through it, deleteDirectory() of the link (default linkHandling)', function (Ctx $c) {
                $outside = sys_get_temp_dir() . '/filefly-probe-outside-' . bin2hex(random_bytes(3));
                mkdir($outside);
                file_put_contents($outside . '/secret.txt', 'OUTSIDE');
                $c->fs->createDirectory($c->dir);
                symlink($outside, $c->localDiskPath($c->p('out')));

                return [
                    'read(out/secret.txt)' => $c->read($c->p('out/secret.txt')),
                    'write(out/new.txt)' => $c->attempt(fn () => $c->fs->write($c->p('out/new.txt'), 'x')) . (is_file($outside . '/new.txt') ? ', file created OUTSIDE the root' : ''),
                    'deleteDirectory(out)' => $c->attempt(fn () => $c->fs->deleteDirectory($c->p('out'))),
                    'outside dir after' => is_file($outside . '/secret.txt') ? 'secret.txt still there' : 'secret.txt DELETED',
                ];
            }, [Backend::KIND_LOCAL]),
            self::row('local.permissions', $g, 'permissions of new files and directories (umask ' . sprintf('%04o', umask()) . ')', function (Ctx $c) use ($mode) {
                $c->fs->write($c->p('a/b/file.txt'), 'x');
                $c->fs->createDirectory($c->p('made'));
                $c->fs->write($c->p('pub.txt'), 'x', ['visibility' => 'public']);
                $c->fs->writeStream($c->p('stream.txt'), self::stream('x'));
                $c->fs->copy($c->p('pub.txt'), $c->p('copied.txt'));

                return [
                    'write file' => $mode($c, $c->p('a/b/file.txt')),
                    'implicit parent dir' => $mode($c, $c->p('a/b')),
                    'createDirectory' => $mode($c, $c->p('made')),
                    'writeStream file' => $mode($c, $c->p('stream.txt')),
                    "write visibility=public" => $mode($c, $c->p('pub.txt')),
                    'copy of public file' => $mode($c, $c->p('copied.txt')),
                    'visibility()' => $c->attempt(fn () => $c->fs->visibility($c->p('a/b/file.txt'))),
                ];
            }, [Backend::KIND_LOCAL]),
        ];
    }

    /**
     * @return list<Scenario>
     */
    private static function s3Requests(): array
    {
        $g = 'S3 request count (commands sent per operation)';
        $setup = static function (Ctx $c): void {
            $c->fs->write($c->p('a.txt'), 'hello');
            $c->fs->write($c->p('dir/one.txt'), '1');
            $c->fs->write($c->p('dir/two.txt'), '2');
            $c->fs->createDirectory($c->p('dir/sub'));
        };
        $rows = [];
        $operations = [
            'fileSize+readStream' => ['fileSize() then readStream() of a file (the streaming sequence)', fn (Ctx $c) => [$c->fs->fileSize($c->p('a.txt')), self::drain($c->fs->readStream($c->p('a.txt')))]],
            'fileSize-missing' => ['fileSize() of a missing file', fn (Ctx $c) => $c->fs->fileSize($c->p('nope.txt'))],
            'fileExists' => ['fileExists() of a file', fn (Ctx $c) => $c->fs->fileExists($c->p('a.txt'))],
            'fileExists-missing' => ['fileExists() of a missing path', fn (Ctx $c) => $c->fs->fileExists($c->p('nope.txt'))],
            'has-file' => ['has() of a file', fn (Ctx $c) => $c->fs->has($c->p('a.txt'))],
            'has-dir' => ['has() of a directory', fn (Ctx $c) => $c->fs->has($c->p('dir'))],
            'has-missing' => ['has() of a missing path', fn (Ctx $c) => $c->fs->has($c->p('nope'))],
            'directoryExists' => ['directoryExists() of a directory', fn (Ctx $c) => $c->fs->directoryExists($c->p('dir'))],
            'mimeType' => ['mimeType()', fn (Ctx $c) => $c->fs->mimeType($c->p('a.txt'))],
            'lastModified' => ['lastModified()', fn (Ctx $c) => $c->fs->lastModified($c->p('a.txt'))],
            'visibility' => ['visibility()', fn (Ctx $c) => $c->fs->visibility($c->p('a.txt'))],
            'write' => ['write() of a new file', fn (Ctx $c) => $c->fs->write($c->p('new.txt'), 'x')],
            'writeStream' => ['writeStream() of a new file', fn (Ctx $c) => $c->fs->writeStream($c->p('new2.txt'), self::stream('x'))],
            'move' => ['move() of a file', fn (Ctx $c) => $c->fs->move($c->p('a.txt'), $c->p('moved.txt'))],
            'copy' => ['copy() of a file', fn (Ctx $c) => $c->fs->copy($c->p('a.txt'), $c->p('copied.txt'))],
            'copy-no-retain' => ["copy() with ['retain_visibility' => false]", fn (Ctx $c) => $c->fs->copy($c->p('a.txt'), $c->p('copied2.txt'), ['retain_visibility' => false])],
            'listContents' => ['listContents() shallow of a directory with 3 entries', fn (Ctx $c) => iterator_to_array($c->fs->listContents($c->p('dir'), false))],
            'listContents-deep' => ['listContents() deep of the same directory', fn (Ctx $c) => iterator_to_array($c->fs->listContents($c->p('dir'), true))],
            'createDirectory' => ['createDirectory()', fn (Ctx $c) => $c->fs->createDirectory($c->p('newdir'))],
            'delete' => ['delete() of a file', fn (Ctx $c) => $c->fs->delete($c->p('a.txt'))],
            'deleteDirectory' => ['deleteDirectory() of the directory with 3 entries', fn (Ctx $c) => $c->fs->deleteDirectory($c->p('dir'))],
        ];
        foreach ($operations as $id => [$title, $operation]) {
            $rows[] = self::row('s3req.' . $id, $g, $title, function (Ctx $c) use ($setup, $operation) {
                $setup($c);

                return ['commands' => $c->s3Commands(fn () => $operation($c))];
            }, [Backend::KIND_S3]);
        }

        return $rows;
    }

    /**
     * @return list<Scenario>
     */
    private static function extras(): array
    {
        $g = 'other differences';

        return [
            self::row('s3.empty-directory-lifecycle', $g, 'S3: empty dir via createDirectory, add and delete a file in it, is the dir still there?', function (Ctx $c) {
                $c->fs->createDirectory($c->p('d'));
                $c->fs->write($c->p('d/a.txt'), 'x');
                $c->fs->delete($c->p('d/a.txt'));
                $c->fs->write($c->p('implicit/a.txt'), 'x');
                $c->fs->delete($c->p('implicit/a.txt'));

                return [
                    'explicit dir after delete of last file' => $c->exists($c->p('d')),
                    'implicit dir after delete of last file' => $c->exists($c->p('implicit')),
                    'list' => $c->tree(),
                    'raw keys' => $c->rawKeys(),
                ];
            }),
            self::row('rename-directory', $g, 'rename a directory by move() (explicit dir with marker, children, nested)', function (Ctx $c) {
                $c->fs->createDirectory($c->p('old'));
                $c->fs->write($c->p('old/a.txt'), 'A');
                $c->fs->write($c->p('old/sub/b.txt'), 'B');

                return [
                    'move' => $c->attempt(fn () => $c->fs->move($c->p('old'), $c->p('new'))),
                    'list deep' => $c->tree(),
                    'raw keys' => $c->rawKeys(),
                ];
            }),
            self::row('ftp.reconnect', $g, 'FTP: control connection closed behind the adapter\'s back, next call', function (Ctx $c) {
                $c->fs->write($c->p('a.txt'), 'x');
                $adapter = (new ReflectionProperty(\League\Flysystem\Filesystem::class, 'adapter'))->getValue($c->fs);
                $connection = (new ReflectionProperty($adapter::class, 'connection'))->getValue($adapter);
                @ftp_close($connection);

                return [
                    'fileExists after close' => $c->attempt(fn () => $c->fs->fileExists($c->p('a.txt'))),
                    'read after close' => $c->read($c->p('a.txt')),
                ];
            }, [Backend::KIND_FTP]),
            self::row('ftp.connect-wrong-password', $g, 'FTP: wrong password (when does it fail?)', function (Ctx $c) {
                $built = $c->attempt(fn () => $c->backend->build(['password' => 'wrong']));
                $fs = null;
                try {
                    $fs = $c->backend->build(['password' => 'wrong'])->getFilesystem();
                } catch (Throwable) {
                }

                return [
                    'build component' => $built,
                    'first call' => $fs ? $c->attempt(fn () => $fs->fileExists('x')) : '-',
                ];
            }, [Backend::KIND_FTP]),
            self::row('ftp.missing-root', $g, 'FTP: component with a root that does not exist', function (Ctx $c) {
                $fs = $c->backend->buildRootedAt($c->p('nope'))->getFilesystem();

                return ['first call' => $c->attempt(fn () => $fs->fileExists('x'))];
            }, [Backend::KIND_FTP]),
            self::row('copy.without-retain-visibility', $g, "copy() with ['retain_visibility' => false] (skips the visibility() lookup of the source)", function (Ctx $c) {
                $c->fs->write($c->p('sub/src.txt'), 'SRC');
                $c->fs->write($c->p('sub/dst.txt'), 'DST');
                $options = ['retain_visibility' => false];

                return [
                    'to missing' => $c->attempt(fn () => $c->fs->copy($c->p('sub/src.txt'), $c->p('sub/new.txt'), $options)) . ', read ' . $c->read($c->p('sub/new.txt')),
                    'onto file' => $c->attempt(fn () => $c->fs->copy($c->p('sub/src.txt'), $c->p('sub/dst.txt'), $options)) . ', read ' . $c->read($c->p('sub/dst.txt')),
                    'with visibility=public' => $c->attempt(fn () => $c->fs->copy($c->p('sub/src.txt'), $c->p('sub/pub.txt'), ['visibility' => 'public'])),
                ];
            }),
            self::row('visibility.same-basename', $g, 'visibility() of sub/x.txt (public) while the component root holds a private x.txt; visibility() of sub/only.txt', function (Ctx $c) {
                $rooted = $c->rooted('r');
                $rooted->write('x.txt', 'root', ['visibility' => 'private']);
                $rooted->write('sub/x.txt', 'sub', ['visibility' => 'public']);
                $rooted->write('sub/only.txt', 'only', ['visibility' => 'public']);

                return [
                    'visibility(x.txt)' => $c->attempt(fn () => $rooted->visibility('x.txt')),
                    'visibility(sub/x.txt)' => $c->attempt(fn () => $rooted->visibility('sub/x.txt')),
                    'visibility(sub/only.txt)' => $c->attempt(fn () => $rooted->visibility('sub/only.txt')),
                ];
            }),
            self::row('write.case-collision', $g, 'write A.txt and a.txt (case sensitivity)', function (Ctx $c) {
                $c->fs->write($c->p('A.txt'), 'upper');
                $c->fs->write($c->p('a.txt'), 'lower');

                return ['read(A.txt)' => $c->read($c->p('A.txt')), 'list' => $c->tree()];
            }),
        ];
    }

    /**
     * @return resource
     */
    private static function stream(string $content)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private static function drain($stream): string
    {
        return 'read ' . strlen((string) stream_get_contents($stream)) . ' bytes';
    }
}
