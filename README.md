FileFly
=======
FlySystem API for [dmstr/yii2-filemanager-widgets](https://github.com/dmstr/yii2-filemanager-widgets)

Installation
------------

#### ENV variables

Variable | Value | Required
------------- | ------------- | -------------
AFM_FILESYSTEM | yii component name | yes
AFM_REPAIR | default: true | no
AFM_SLUG_NAMES | default: true | no

i.e. `AFM_FILESYSTEM=fsLocal`

:info: How to configure a filesystem component [Filesystem docs](https://github.com/creocoder/yii2-flysystem/blob/master/README.md)

#### Yii config

```
'filefly' => [
    'class'              => 'hrzg\filefly\Module',
    'layout'             => '@backend/views/layouts/main',
    'filesystem'         => getenv('AFM_FILESYSTEM'),
    'slugNames'			 => (getenv('AFM_SLUG_NAMES')) ? getenv('AFM_SLUG_NAMES') : true,
    'repair'             => (getenv('AFM_REPAIR')) ? getenv('AFM_REPAIR') : true,
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
- `FileflyAdmin` dürfen/können alles!
- `FileflyPermissions` berechtigte können Berechtigungen in Rahmen ihnen zugewiesenen RBAC Berechtigungen setzen und ändern.
- Solange keine Berechtigungen gesetzt sind, wird down up geschaut ob irgendwo das geforderte Recht gegeben ist.
- Besitzer Berechtigungen gehen über die gesetzten Rechte in `access_read`, `access_update`, `access_delete`

**ActiveRecord: FileflyHashmap**
- uses `dmstr\db\traits\ActiveRecordAccessTrait` with `$activeAccessTrait = false`
- access checks will be done foreach permission type explicitly, `hasPermission($action)`
- uses a `pathValidator` rule to ensure the `path` syntax

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
