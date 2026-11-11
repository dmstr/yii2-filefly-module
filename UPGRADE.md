<!-- file generated with AI assistance: Claude Code - [2026-11-12 12:00:00 UTC] -->

# Upgrading from 1.x to 2.0

2.0 moves the module from Flysystem 1 to Flysystem 3. The module's own API (HTTP actions, console command `fs`, `FileManager::storage()`, `FileManager::access()`, the hashmap) keeps working as before, except for the changes listed below; what applications have to change is mostly the configuration of their filesystem components.

## Requirements

- PHP `^8.1`
- `creocoder/yii2-flysystem` `^2.0` instead of `^1.0`; its components keep their class names (`creocoder\flysystem\LocalFilesystem`, `AwsS3Filesystem`, `FtpFilesystem`, ...)
- `league/flysystem` `^3.35.3`: the minimum is deliberate, advisory `PKSA-w9tt-7782-78jx` affects all versions up to 3.35.2 and every 1.x release, which is the reason for this migration. Remove any `config.audit.ignore` entry you added for it.
- The adapter packages are separate packages in Flysystem 3 and are not installed by the module. An application requires the ones it uses: `league/flysystem-aws-s3-v3` `^3` for `AwsS3Filesystem`, `league/flysystem-ftp` `^3` for `FtpFilesystem` (the local adapter `league/flysystem-local` comes with the module).

```bash
composer require "dmstr/yii2-filefly-module:^2.0" "creocoder/yii2-flysystem:^2.0" \
  "league/flysystem-aws-s3-v3:^3" "league/flysystem-ftp:^3"
```

## Filesystem component configuration

The module accepts as `filesystem` component a `creocoder\flysystem\Filesystem` of creocoder/yii2-flysystem 2 or any `League\Flysystem\FilesystemOperator`. An option of creocoder 1.x that no longer exists makes the component fail when it is created (`Setting unknown property`), so check each component against the lists below.

All components: the caching and replica options of creocoder 1.x (`cache`, `cacheKey`, `cacheDuration`, `replica`) are gone; `config` still exists and is passed to Flysystem 3 as default config of every call.

### Local (`LocalFilesystem`)

- Permissions of new directories: creocoder 2 creates directories, including the parent directories created for a write, copy or move, with `0740` (private), Flysystem 1 created them with `0755`. Other users, for example the web server or a second container on the same volume, can then no longer enter them. Recommended, to keep `0755` (minus the process umask):

```php
'fsLocal' => [
    'class' => \creocoder\flysystem\LocalFilesystem::class,
    'path' => '@storage',
    'config' => ['directory_visibility' => 'public'],
],
```

  Do not use `defaultForDirectories` for this: creocoder 2.0.0 passes `defaultForFiles` to Flysystem as the default for directories and ignores `defaultForDirectories`. New files get `0644` (minus umask) as before, as long as no `visibility` is configured. The old option `permissions` is replaced by `filePublic`, `filePrivate`, `directoryPublic` and `directoryPrivate`. A root directory (`path`) that does not exist yet is created with `directoryPrivate` (`0740`), regardless of `directory_visibility`.
- Links: keep the default `linkHandling` (`DISALLOW_LINKS`). The module checks the last path segment for a symlink itself and behaves as in 1.x: `stream`, `download`, `list` (any link in the listed directory) and `remove` of a link fail, `fs ls` reports an error. With `SKIP_LINKS` links are left out of listings and answered as not found by `stream`/`download`. In both modes the module never deletes a symlinked directory as directory (Flysystem 3 would delete the contents of the link target, also outside the root). Links inside a path (a symlinked directory) are followed, as in 1.x; do not place links in the storage root that point outside of it.

### S3 (`AwsS3Filesystem`)

- The adapter class is `League\Flysystem\AwsS3V3\AwsS3V3Adapter` instead of `League\Flysystem\AwsS3v3\AwsS3Adapter`; this matters only for application code that checks or builds the adapter itself.
- The component options `baseUrl`, `options` and `streamReads` of creocoder 1.x are gone. `defaultVisibility` (default `private`) is new; it sets the ACL of the directory marker objects that `createFolder` creates. Uploads get the ACL of a `visibility` in the component's `config`, `private` without one, as in 1.x.
- Copies and moves (`copy`, `move`, `rename`, `fs mv` within one bucket) are made without retaining the visibility of the source: Flysystem 3 would otherwise read the source ACL first (one more request per copy, and a failure on S3 servers without ACL support). A copied or moved object gets the ACL of the `visibility` in the component's `config` (`private` without one), also when the source was public; 1.x kept the ACL of the source.

### FTP (`FtpFilesystem`)

- `enableTimestampsOnUnixListings` is now `timestampsOnUnixListingsEnabled`.
- The options are passed to a strictly typed constructor: `port` and `timeout` must be integers, `ssl`, `passive` and `utf8` booleans, `transferMode` must not be `null` (default `FTP_BINARY`). Values from environment variables have to be cast, for example `'port' => (int)getenv('FTP_PORT')`, `'ssl' => filter_var(getenv('FTP_SSL'), FILTER_VALIDATE_BOOLEAN)`.
- `permPublic` and `permPrivate` are replaced by `filePublic`, `filePrivate`, `directoryPublic` and `directoryPrivate`.
- Known adapter limits of Flysystem 3 against real servers (vsftpd, ProFTPD, Pure-FTPd) are recorded in `tests/adapters/RESULTS.md` of this repository.

## Storage behaviour per backend

The module reaches every filesystem through `hrzg\filefly\components\Storage`, which keeps one contract on the local adapter, S3 and FTP: no write, copy or move replaces anything (only `fs sync` replaces older files), a path that leaves the root is refused before any request, nothing outside the component's root or `prefix` is reachable, deleting never touches siblings that share a name prefix, and a directory stays when its last item is deleted or moved away. The adapter suite in `tests/adapters` (see its `README.md`) runs the same contract tests against the local adapter, Garage and RustFS (S3), and vsftpd, ProFTPD and Pure-FTPd in plain FTP and explicit FTPS; it was not run against AWS S3 itself or other FTP servers. Below is what differs per backend and what applications have to configure. None of the operations is atomic: every check-then-act takes two or more requests on every backend, so two writers racing for the same path are not detected.

### Local

- Package: `league/flysystem-local` comes with the module. Configuration: see "Local (`LocalFilesystem`)" above (`'config' => ['directory_visibility' => 'public']` keeps `0755` for new directories).
- Links: keep `linkHandling` at `DISALLOW_LINKS` (see above). A symlinked directory is never deleted as directory (`fs rmdir` and `remove` fail and keep link and target); `fs rm` of a link to a file removes only the link.
- Deviation: `Storage::openFile()` and `Storage::fileStream()` of a directory do not fail (no stream on macOS, an empty stream that fails on the first read on Linux). The HTTP actions check with `fileStat()` first and answer 404; `fs cp` of a directory leaves an empty file at the target (pinned in the default suite).
- Error messages of the local adapter can contain absolute disk paths; the module logs them and never shows them to end users.

### S3 (`AwsS3Filesystem`)

- Packages: `league/flysystem-aws-s3-v3` `^3` (pulls in `aws/aws-sdk-php`). Configuration as for 1.x without the removed options (see "S3" above); for S3-compatible servers set `endpoint` and `'pathStyleEndpoint' => true`. Set a `prefix` per application or environment: nothing outside it can be reached through the module, whatever path a request sends.
- Directories: S3 has none, a directory is a key prefix. The module treats a directory as existing while a marker object `<dir>/` or any object below it exists: `createFolder` (and `fs mkdir`) writes one marker for the deepest level, the levels above exist through it. Before an item is deleted or moved away the module writes a marker for its parent, so a folder never vanishes with its last file (and its hashmap row stays valid). Recursive listings list every directory, also those without a marker.
- Hierarchy: the module refuses a file or directory below a key that is a file (`f.txt/child`), as local and FTP do, at one `HeadObject` per parent level of the target. A bucket written by other tools may still contain the key `f` and keys below `f/`; the module then shows a file and a directory of the same name and adds nothing to either.
- Moving and renaming a folder copies every object below it to the new prefix and then deletes the old prefix (S3 has no rename). If this fails half-way the error says in which phase: while copying the source is unchanged and the target holds a partial copy (remove it before retrying); while deleting the target is complete and the source may be partly left. Large folders cost one copy request per object.
- Deviation: directories have no modification time. Listings report `null` for them (the API shows the time of the request), `Storage::lastModified()` of a directory returns `null`, and `fs sync` therefore treats a source file whose target is a directory as newer and stops with `ItemExistsException` (the directory is kept) instead of skipping it as on local.
- Copies and moves do not read the source ACL (`retain_visibility` off, see above), so servers without ACL support (Garage) work.
- Request costs (pinned by the adapter suite, item in the root; one more `HeadObject` per parent level of a target below the root):

| Operation | S3 requests |
| --- | --- |
| `stream`, `download` of a file (`fileStat()` + `fileStream()`) | `HeadObject`, `GetObject` |
| `stream`, `download` of a missing file or a directory | `HeadObject` |
| any request with a path leaving the prefix | none |
| `exists()` of a file | `HeadObject` |
| `exists()` of a directory or a missing path | `HeadObject`, `ListObjectsV2` |
| listing, shallow or recursive | `ListObjectsV2` per 1000 keys |
| upload, `writeFile()` | `HeadObject`, `ListObjectsV2`, `PutObject` |
| copy, `copyFile()` | `HeadObject`, `HeadObject`, `ListObjectsV2`, `HeadObject`, `CopyObject` |
| move or rename of a file, `move()` | `HeadObject`, `HeadObject`, `ListObjectsV2`, `HeadObject`, `CopyObject`, `DeleteObject` |
| `lastModified()` of a file (`fs sync`, `list` without listing time) | `HeadObject`, `HeadObject` |
| `lastModified()` of a directory | `HeadObject`, `ListObjectsV2` |

  Moving a file out of its folder, deleting a file and deleting a folder below the root add one `PutObject` (the parent's marker); deleting a folder is one listing plus one `DeleteObjects` per 1000 keys.

### FTP (`FtpFilesystem`)

- Package: `league/flysystem-ftp` `^3`, which needs `ext-ftp` (built with OpenSSL for FTPS). Configuration: see "FTP" above; set `'timestampsOnUnixListingsEnabled' => true`, otherwise listings carry no times and the API `list` action asks for each item separately (`SIZE` and `MDTM`).
- Failed uploads: ext-ftp raises a PHP warning before the adapter reports the failure; the module holds such warnings back while it calls the adapter and drops them when the adapter reports the failure, so the request ends in the module's own error answer instead of a `yii\base\ErrorException`. A warning without a following failure reaches the application's error handler unchanged.
- Deviations: `Storage::lastModified()` of a directory returns `null` (`MDTM` answers for files only; listings do carry directory times); listing times have minute precision and the year is guessed from the `ls` format, while `lastModified()` of a file has second precision. `fs sync` onto a directory behaves as on S3 (see above).
- Listing a file path returns an empty listing on every server (ProFTPD answers with an entry carrying the absolute server path, which the module drops).
- Server settings: ProFTPD with TLS 1.3 enabled and its default `TLSOptions` requires TLS session reuse on data connections, which PHP's ext-ftp provides only for the first data connection; every later transfer fails. Use `TLSProtocol TLSv1.2` or `TLSOptions NoSessionReuseRequired` (see `tests/adapters/RESULTS.md`, item 20). Passive mode must be reachable from the application; `ignorePassiveAddress` (default `true`) connects to the control host.

## Removed API

- The Flysystem plugins in `hrzg\filefly\plugins` (`AccessPlugin`, `SelfHealKit`, `GrantAccess`, `SetAccess`, `RemoveAccess`, `GetPermissions`, `UpdatePermission`, `RecursiveIterator`): Flysystem 3 has no plugins. Use `FileManager::access()`, an `AccessManager` with the methods `check()`, `grantAccess()`, `setAccess()`, `removeAccess()`, `getPermissions()`, `updatePermission()` and `isEmpty()`.
- `FileManager::fileSystem()` and the magic methods it registered on the filesystem component (`$fs->check()`, `$fs->grantAccess()`, ...): use `FileManager::access()` for permissions and `FileManager::storage()` (`hrzg\filefly\components\Storage`) for files.
- The Flysystem 1 methods on the filesystem component itself: creocoder 2 forwards calls to the Flysystem 3 filesystem, which has other names and semantics (`createDir()` is `createDirectory()`, `deleteDir()` is `deleteDirectory()`, `rename()` is `move()`, `getTimestamp()`/`getSize()`/`getMimetype()` are `lastModified()`/`fileSize()`/`mimeType()`, `put()`/`update()` are gone and `write()` replaces existing files, `getMetadata()` and `get()` are gone, `listContents()` returns attribute objects instead of arrays, and failures throw instead of returning `false`). Application code that calls the component directly has to be ported; `Storage` keeps the module's semantics (no silent overwrite, module exceptions).

## Behaviour changes in 2.0

- Rename and move change only the hashmap rows of the item itself and of the items below it (`<old>/`), and replace only the leading old path; rows of siblings that merely share the prefix (`/dd` for `/d`, `/a.txt.bak` for `/a.txt`) are no longer touched, and `%` and `_` in names are matched literally. Removing a directory and the emptiness check of `remove` select rows the same way.
- `createFolder` on an existing file or directory changes no hashmap row (1.x renamed an existing row to `/`); upload and copy onto a path with a stale row renew that row for the new file instead of rewriting it to `/`.
- `move`, `copy` and `rename` onto an existing file or directory (or onto the source itself) answer HTTP 200 with `{"result": {"success": false, "error": "File already exists"}}` and fire the action's error event, instead of HTTP 500; nothing is overwritten. A missing source still ends in HTTP 500.
- `fs sync` (and `FsManager::sync()`) replaces target files that are older than their source instead of throwing; a directory at the target of a newer source file is still refused.
- Access checks: `changePermissions` and `resolvePermissions` are allowed only for the owner of the item's own row, RBAC `Admin` and users with `FileflyAdmin` assigned directly; `move` needs update access on each source item, `copy` read access on each source item; `rename` into another folder needs update access on that folder. See "Access control" in `README.md`.
