Yii2 FileFly Module
=======

[![Latest Stable Version](https://poser.pugx.org/dmstr/yii2-filefly-module/v/stable.svg)](https://packagist.org/packages/dmstr/yii2-filefly-module)
[![Total Downloads](https://poser.pugx.org/dmstr/yii2-filefly-module/downloads.svg)](https://packagist.org/packages/dmstr/yii2-filefly-module)
[![License](https://poser.pugx.org/dmstr/yii2-filefly-module/license.svg)](https://packagist.org/packages/dmstr/yii2-filefly-module)

FlySystem API for [dmstr/yii2-filemanager-widgets](https://github.com/dmstr/yii2-filemanager-widgets)

Installation
------------

#### ENV variables

Variable | Value | Required
------------- | ------------- | -------------
AFM_FILESYSTEM | yii component name | yes
AFM_REPAIR | default: true | no
AFM_SLUG_NAMES | default: true | no
AFM_DELETE_RECURSIVE | default: false | no

i.e. `AFM_FILESYSTEM=fsLocal`

:question: How to configure a filesystem component [Filesystem docs](https://github.com/creocoder/yii2-flysystem/blob/master/README.md)

#### Yii config

```php
'filefly' => [
    'class'              => 'hrzg\filefly\Module',
    'layout'             => '@backend/views/layouts/main',
    'filesystem'         => getenv('AFM_FILESYSTEM'),
    'slugNames'			 => (getenv('AFM_SLUG_NAMES')) ? getenv('AFM_SLUG_NAMES') : true,
    'repair'             => (getenv('AFM_REPAIR')) ? getenv('AFM_REPAIR') : true,
    'deleteRecursive'    => (getenv('AFM_DELETE_RECURSIVE')) ? getenv('AFM_DELETE_RECURSIVE') : false,
    'streamExpireOffset' => 604800 # Offset (in seconds) for Expires Header in stream action
    'defaultPermissions' => [
        \hrzg\filefly\Module::ACCESS_OWNER  => 1,
        \hrzg\filefly\Module::ACCESS_READ   => \hrzg\filefly\models\FileflyHashmap::$_all,
        \hrzg\filefly\Module::ACCESS_UPDATE => \hrzg\filefly\models\FileflyHashmap::$_all,
        \hrzg\filefly\Module::ACCESS_DELETE => \hrzg\filefly\models\FileflyHashmap::$_all,
    ],
    # the urlCallbck property can be used to provide customized urls for each file item which (if defined) will overwrite 
    # the default handler URLs
    'urlCallback'        => function($item) {
		$urls = [];
		$isImageFileExtList = ['jpg', 'jpeg', 'gif', 'tiff', 'tif', 'svg', 'png', 'bmp'] ;
		if ($item['type'] === 'file') {
			if (in_array(strtolower($item['extension']), $isImageFileExtList)) {
				$urls['image url'] = \dmstr\willnorrisImageproxy\Url::image($item['path']);
			}
			else {
				$urls['download url'] = implode('/', ['/img/download', ltrim($item['path'], '/')]) . ',p1';
			}
		}
		return $urls;
	},
	// previewCallback can be used to overwrite the default downloadUrl for preview URLs within filemanagerApp
	'previewCallback' => function($item) {
		$isImageFileExtList = ['jpg', 'jpeg', 'gif', 'tiff', 'tif', 'png', 'bmp'] ;
		if ($item['type'] === 'file') {
			if (in_array(strtolower($item['extension']), $isImageFileExtList)) {
				return \dmstr\willnorrisImageproxy\Url::image($item['path'], '500x');
			}
		}
		return '';
	}
],
```

## Upload validation

Uploaded files are validated on the server before they are written to the filesystem. The same extension rules are applied to the new file name on rename and copy.

All rules are disabled by default, so existing installations keep their behavior. File names with control characters, null bytes or path separators are always rejected.

Property | Default | Description
------------- | ------------- | -------------
allowedExtensions | `null` | allowed extensions (case-insensitive), checked against the last extension, `null` allows all
deniedExtensions | `[]` | denied extensions, checked against every extension of the file name, so `shell.php.jpg` is rejected if `php` is listed
denyMultipleExtensions | `false` | reject file names with more than one extension like `demo.pdf.jpg` (note that this also rejects names like `archive.tar.gz`)
maxFileSize | `null` | max size of an uploaded file in bytes or as shorthand like `50M`

Both the original file name and the name after slugging (`slugNames`) are checked. Trailing dots and spaces are ignored when the extensions are determined, so `shell.php.` is treated like `shell.php`.

Recommended configuration:

```php
'filefly' => [
    // ...
    'allowedExtensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'docx', 'xlsx', 'zip'],
    'deniedExtensions' => \hrzg\filefly\components\UploadValidator::DANGEROUS_EXTENSIONS,
    'denyMultipleExtensions' => true,
    'maxFileSize' => '50M',
],
```

`maxFileSize` only works within the limits of PHP (`upload_max_filesize`, `post_max_size`) and the web server or proxy (e.g. `client_max_body_size` in nginx), which should be set accordingly.

## RBAC

**Prosa**
- `FileflyAdmin` full module access including debug information
- `FileflyDefault` management access (eg. POST request for upload, change, delete)
- `FileflyPermissions` assigned users can set or unset roles or permissions which the user himself has assigned

- `access_owner` permission before `access_read`, `access_update`, `access_delete`
- If no permission is set, it will check if any inherited permission can be granted

So if inherited `access_*` permissions should be used, set defaultPermissions to `null` NOT to `'*'`.

```
            'defaultPermissions' => [
                \hrzg\filefly\Module::ACCESS_OWNER  => 1,
                \hrzg\filefly\Module::ACCESS_READ   => null,
                \hrzg\filefly\Module::ACCESS_UPDATE => null,
                \hrzg\filefly\Module::ACCESS_DELETE => null,
            ],
```

**ActiveRecord: FileflyHashmap**
- uses `dmstr\activeRecordPermissions\ActiveRecordAccessTrait` with `$activeAccessTrait = false`
- access checks will be done for each permission type explicitly, `hasPermission($action)`
- uses a `pathValidator` rule to ensure the `path` syntax on active record operations

#### Roles

- FileflyAdmin
	- filefly

- FileflyDefault
	- filefly_default_index

- FileflyApi
	- filefly_api_index

- FileflyPermissions

#### Permissions

- filefly
- filefly_default_index
- filefly_api_index

## Access control

Access checks and the hashmap maintenance are done by `hrzg\filefly\components\AccessManager`; `FileManager::access()` returns one for the filesystem of the current module, with the methods `check()`, `grantAccess()`, `setAccess()`, `removeAccess()`, `getPermissions()`, `updatePermission()` and `isEmpty()`.

The Flysystem plugins in `hrzg\filefly\plugins` and the magic methods of the same names on `FileManager::fileSystem()` are deprecated since 1.9; they delegate to `AccessManager` and are removed in 2.0 together with Flysystem 1.

Permission checks will ever come after file or folder operation

Rights needed per action (besides the route permission and, for POST requests, the role `FileflyDefault`): `list` and `search` read access (`stream` and `download` check no item access); `upload` and `createFolder` update access on the target folder; `rename` update access on the item and, when the item goes to another folder, on that folder; `move` update access on the target folder and on each item; `copy` update access on the target folder and read access on each item; `remove` delete access on each item. `changePermissions` and `resolvePermissions` are allowed only for the owner of the item (`access_owner` of its own record), users with the RBAC role `Admin` and users with `FileflyAdmin` assigned directly; the permissions of `/` are managed by admins only.

**grantAccess**
```
Granted or deny permission

1. access field empty (is owner, true or continue)
2. access field set (permission granted, true)
   access field set (is access owner, true, permission denied, false)
```

**setAccess**
```
Create or update permission

1. Add new hash records
2. Update hash records (recursive option)

- Multi create and update option
```

`setAccess($path)` (without new path) creates the record of a newly created item (upload, copy, createFolder) with the acting user as owner and the access columns from `defaultPermissions`; a stale record that already exists for exactly this path is overwritten the same way, and no other record changes. A `createFolder` on an existing file or directory changes no record. Moving the records of a renamed or moved item (`setAccess($oldPath, $newPath)`) rewrites exactly the record of `$oldPath` and the records below `$oldPath/`, and replaces only the leading `$oldPath`; records that merely share the prefix (`/dd` for `/d`, `/a.txt.bak` for `/a.txt`) are not touched. `removeAccess()` and `isEmpty()` select the records below an item the same way. `%` and `_` in paths are matched literally.

**removeAccess**
```
Remove permission

1. Deletes file or folder records

- Multi delete option
```

## CLI

Configure

    'controllerMap' => [
        'fs' => [
            'class' => '\hrzg\filefly\commands\FsController',
            'filesystemComponents' => [
                'local' => 'fs',
                's3' => 'fsS3',
                'storage' => 'fsFtp',
            ],
        ],
    ]

## Widget

see https://github.com/dmstr/yii2-filemanager-widgets

```
    {{ use ('hrzg/filemanager/widgets') }}
    {{ file_manager_widget_widget(
        {
            "handlerUrl": "/#{app.language}/filefly/api"
        }
    ) }}
```

Options for the yii2-filemanager-widgets Widget can be injected via the Module property `fileManagerWidgetOptions`

example:
```php

        'filefly' => [
            'filesystemComponents' => [
                'ftp' => 'fsFtp',
                'ftpcrud' => 'fsFtpCrud',
            ],
            // ...
            // other configs
            // ...
            // Options for the angular fileManagerWidget
            'fileManagerWidgetOptions' => [
                'searchForm' => false,
                'allowedActions' => [
                    'move' => false,
                    'upload' => false,
                    'createFolder' => false,
                    ],
            ],
        ],
```        

### Controller action in iFrame

Modal button

```
<a type="button" data-toggle="modal" data-target="#modal-filemanager" id="filefly-iframe-toggle">  
    <i class="fa fa-folder-open"></i>
</a>
```

Load event listener

```
<script>
    window.addEventListener('load', function () {
        var fileflyIframeToggle = document.querySelector('#filefly-iframe-toggle');
        var iframe = document.querySelector('#filefly-iframe');
        
        fileflyIframeToggle.addEventListener('click', function () {
            iframe.src = "/filefly/default/filemanager-full-screen";
        });
    });
</script>
```

Iframe
```
{{ use ('yii/bootstrap') }}
{{ modal_begin(    
{        
    'id': 'modal-filemanager',
	'size': 'modal-lg'    
}) }}

<iframe id="filefly-iframe"  width="100%" height="100%" frameborder="0" src=""></iframe>

{{ modal_end() }}'
```

## Helper

Description | Method call | Example output
--- | --- | ---
Total size for all filesystems | `FileflyHashmap::getTotalSize()` | 202.82 MiB
Total size for all filesystems (raw bytes) | `FileflyHashmap::getTotalSize(true)` | 212670464
Total size for `local` filesystems | `FileflyHashmap::getTotalSize(false, 'local')` | 48.32 MiB
Total size for `s3` filesystems (raw bytes) | `FileflyHashmap::getTotalSize(true, 's3')` | 166546843

## Content security

### Delivery of active content

The `stream` and `download` actions deliver files from the same origin as the application. SVG, HTML, XML and script files could run embedded JavaScript in the context of the application when a browser opens them directly. These files are therefore delivered with `Content-Disposition: attachment` and a sandbox `Content-Security-Policy`, and every delivered file gets `X-Content-Type-Options: nosniff`. Embedding SVG files with `<img>` keeps working. This is enabled by default.

```php
'deliveryPolicy' => [
    // 'enabled' => true,
    // MIME types delivered as attachment, shell wildcards are supported
    // 'activeContentMimeTypes' => ['text/html', 'image/svg+xml', '*+xml', ...],
    // 'contentSecurityPolicy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
    // 'noSniff' => true,
],
```

The type is checked for the MIME type reported by the filesystem and for the type derived from the file extension. The sandbox CSP is added after `Response::EVENT_BEFORE_SEND` handlers from the application config, so an application wide CSP header does not replace it.

### Upload content validation

Uploaded files are checked by `hrzg\filefly\components\ContentValidator` before they are written. The MIME type is detected from the file content (fileinfo), not taken from the client. By default only PHP files are rejected.

Property | Default | Description
--- | --- | ---
`enabled` | `true` | Enable content checks
`blockedMimeTypes` | PHP types | Detected MIME types that are rejected, shell wildcards are supported
`checkMimeTypeMatchesExtension` | `false` | Reject files whose content does not match the extension, e.g. a script named `image.jpg`
`mimeTypeAliases` | `[]` | Additional accepted MIME types per extension, e.g. `['dwg' => ['application/octet-stream']]`
`sanitizeSvg` | `false` | Remove scripts, event handlers and remote references from SVG files, requires `enshrined/svg-sanitize`

The existing `mime-whitelist` setting (`filefly` section) is still checked after the content validation.

#### Virus scanner and own checks

The validator triggers `ContentValidator::EVENT_VALIDATE_FILE` for every file that passed the built-in checks. Set `$event->isValid = false` to reject the file. The module has no dependency on a scanner, e.g. ClamAV can be connected by calling `clamdscan`:

```php
'contentValidator' => [
    'on validateFile' => function (\hrzg\filefly\events\UploadValidationEvent $event) {
        exec('clamdscan --no-summary --fdpass ' . escapeshellarg($event->tempName), $output, $exitCode);
        if ($exitCode !== 0) {
            $event->isValid = false;
            // optional message key from messages/*.json, default: file_content_rejected
            // $event->errorMessage = 'file_content_rejected';
        }
    },
],
```

### Archives

The API has no `compress` and `extract` actions, so archives are never unpacked on the server. The filemanager view of this module hides both actions in the widget. If the widget is rendered elsewhere, disable them via `'allowedActions' => ['compress' => false, 'extract' => false]`.

### Recommended configuration

```php
'filefly' => [
    // ...
    'contentValidator' => [
        'blockedMimeTypes' => \hrzg\filefly\components\ContentValidator::RECOMMENDED_BLOCKED_MIME_TYPES,
        'checkMimeTypeMatchesExtension' => true,
        // 'sanitizeSvg' => true,
        // 'on validateFile' => function ($event) { /* virus scan */ },
    ],
],
```

`RECOMMENDED_BLOCKED_MIME_TYPES` blocks server side scripts (PHP, shell, Perl, Python, ...), executables and HTML/JavaScript files.
