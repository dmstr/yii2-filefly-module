FileFly
=======
FlySystem API for Filemanager

Installation
------------

#### ENV variables

Variable | Value
------------- | -------------
AFM_FILESYSTEM | 'yii component name'

i.e. `AFM_FILESYSTEM=fsLocal`

:info: How to configure a filesystem component [Filesystem docs](https://github.com/creocoder/yii2-flysystem/blob/master/README.md)

#### Yii config

```
'filefly' => [
    'class' => 'hrzg\filefly\Module',
    'layout' => '@backend/views/layouts/main',
    'filesystem' => getenv('FILEFLY_FILESYSTEM')
],
```

## RBAC

**Prosa**
- Filefly Admins dürfen/können alles!
- Solange keine Berechtigungen gesetzt sind, wird down up geschaut ob irgendwo das geforderte Recht gegeben ist.
- Besitzer Berechtigungen gehen über die gesetzten Rechte in `access_read`, `access_update`, `access_delete`
- Sobald auf diesem Weg ein Recht vorhanden -> berechtigt
- Sobald auf diesem Weg ein Recht verweigert -> untersagt

**ActiveRecord: FileflyHashmap**
- uses `dmstr\db\traits\ActiveRecordAccessTrait` with `$activeAccessTrait = false`
- access checks will be done foreach permission type explicitly, `hasPermission($action)`

#### Roles

- FileflyAdmin
	- filefly
	
- FileflyDefault
	- filefly_default_index
	
- FileflyApi
	- filefly_api_index
	
#### Permissions

- filefly
- filefly_default_index
- filefly_api_index

## RBAC Plugins

Permission checks will ever come before file or older operation

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

- Multi create option
```

**RemovePermission**
```
Remove permission

1. Deletes file or folder records

- Multi delete option
```

## TODOs

- input validation of file and folder names ! Missing in native Angular-Filemanager

Probs:
------
1. Die DB hat ein Index über (Filesystem / Path / Owner / Domain)
Jetzt kommt ein 2. Benutzer und will einen Ordner mit selben Namen auf der selben Ebene anlegen.
Die DB kanns aufgrund des 4er Index verarbeiten, das Filesystem aber nicht !! 
Von unterschiedlichen Sprachen ist da noch nichtmal die Rede.

Mögliche Lösungen:
---
1. Ordner dürfen nur von einem Owner angelegt werden oder die Fehlermeldung weisst eben darauf hin,
dass es an dieser Stelle schon ein Ordner mit selben Namen gibt?!