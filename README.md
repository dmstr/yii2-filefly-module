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

```
'filefly' => [
    'class'              => 'hrzg\filefly\Module',
    'layout'             => '@backend/views/layouts/main',
    'filesystem'         => getenv('AFM_FILESYSTEM'),
    'slugNames'			 => (getenv('AFM_SLUG_NAMES')) ? getenv('AFM_SLUG_NAMES') : true,
    'repair'             => (getenv('AFM_REPAIR')) ? getenv('AFM_REPAIR') : true,
    'deleteRecursive'    => (getenv('AFM_DELETE_RECURSIVE')) ? getenv('AFM_DELETE_RECURSIVE') : false,
    'defaultPermissions' => [
        \hrzg\filefly\Module::ACCESS_OWNER  => 1,
        \hrzg\filefly\Module::ACCESS_READ   => \hrzg\filefly\models\FileflyHashmap::$_all,
        \hrzg\filefly\Module::ACCESS_UPDATE => \hrzg\filefly\models\FileflyHashmap::$_all,
        \hrzg\filefly\Module::ACCESS_DELETE => \hrzg\filefly\models\FileflyHashmap::$_all,
    ]
],
```

## RBAC

**Prosa**
- `FileflyAdmin` have all access!
- `FileflyPermissions` assigned users can set or unset roles or permissions which the user himself has assigned

- If no permission is set, it will check if any inherited permission can be granted
- `access_owner` permission before `access_read`, `access_update`, `access_delete`

**ActiveRecord: FileflyHashmap**
- uses `dmstr\db\traits\ActiveRecordAccessTrait` with `$activeAccessTrait = false`
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

## RBAC Plugins

Permission checks will ever come after file or older operation

**GrantPermission**
```
Granted or deny permission 

1. access field empty (is owner, true or continue)
2. access field set (permission granted, true)
   access field set (is access owner, true, permission denied, false)
```

**SetPermission**
```
Create or update permission

1. Add new hash records
2. Update hash records (recursive option)

- Multi create and update option
```

**RemovePermission**
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
    
## Helper

Description | Method call | Example output 
--- | --- | ---
Total size for all filesystems | `FileflyHashmap::getTotalSize()` | 202.82 MiB 
Total size for all filesystems (raw bytes) | `FileflyHashmap::getTotalSize(true)` | 212670464
Total size for `local` filesystems | `FileflyHashmap::getTotalSize(false, 'local')` | 48.32 MiB 
Total size for `s3` filesystems (raw bytes) | `FileflyHashmap::getTotalSize(true, 's3')` | 166546843
