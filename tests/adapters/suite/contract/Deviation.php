<?php
// file generated with AI assistance: Claude Code - [2026-11-12 16:00:00 UTC]

namespace hrzg\filefly\tests\adapters\suite\contract;

/**
 * The documented deviations of a backend from the storage contract: the only names a contract
 * test may branch on. Each backend lists its deviations in one place (deviations() of its test
 * case); every entry is described in `UPGRADE.md` ("Storage backends").
 *
 * A deviation is a limit of the storage that the facade cannot hide at a reasonable cost. A
 * deviation that would be unsafe (overwriting, deleting more than asked, reaching outside the
 * root) is never listed here: the facade is fixed instead.
 */
final class Deviation
{
    /**
     * Directories have no modification time: listing items of directories carry `null` as
     * timestamp and Storage::lastModified() of a directory returns `null` (S3: a directory is a
     * key prefix, a marker object has no time Flysystem reports).
     */
    public const DIRECTORY_TIMESTAMPS_NONE = 'directory timestamps unavailable';

    /**
     * Storage::lastModified() of a directory returns `null`; listing items of directories carry a
     * time (FTP: MDTM answers only for files, the listing has the `ls` time).
     */
    public const DIRECTORY_LAST_MODIFIED_NONE = 'lastModified() of a directory unavailable';

    /**
     * Timestamps in listings have minute precision (FTP: the `ls` time format), while
     * Storage::lastModified() of a file has second precision (MDTM).
     */
    public const LISTING_TIMESTAMPS_MINUTES = 'listing timestamps have minute precision';

    /**
     * Storage::openFile() and Storage::fileStream() of a directory do not fail: the local adapter
     * opens the directory (no stream on macOS, an empty stream that fails on the first read with
     * a PHP notice on Linux). The HTTP actions call fileStat() first, which answers `null`.
     */
    public const DIRECTORY_OPENS_AS_STREAM = 'a directory can be opened as stream';
}
