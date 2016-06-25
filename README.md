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

### RBAC

**Prosa**
- Filefly Admins dürfen/können alles!
- Solange keine Berechtigungen gesetzt sind, wird down up geschaut ob irgendwo das geforderte Recht gegeben ist.
- Besitzer Berechtigungen gehen über die gesetzten Rechte in `access_read`, `access_update`, `access_delete`
- Sobald auf diesem Weg ein Recht vorhanden -> berechtigt
- Sobald auf diesem Weg ein Recht verweigert -> untersagt

**Plugin: findPermission**
```
How the plugin works if permission will be granted or denied

1. access empty (is owner, true or continue)
2. access set (permission granted, true)
   access set (is access owner, true, permission denied, false)
```

**Plugin: setPermission**
```
List:
...
Upload:
...
Rename:
...
Copy:
...
Move:
...
Edit:
...
CreateFolder:
...
```

**Plugin: removePermission**
```
Remove:
- deletes the file or folder hash first, then do the file remove operation
```

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


## TODOs

- input validation of file and folder names !

Probs:
------
1. Die DB hat ein Index über (Filesystem / Path / Owner / Domain)
Jetzt kommt ein 2. Benutzer und will einen Ordner mit selben Namen auf der selben Ebene anlegen.
Die DB kanns aufgrund des 4er Index verarbeiten, das Filesystem aber nicht !! 
Von unterschiedlichen Sprachen ist da noch nichtmal die Rede.

2. Plugin findPermission erlaubt Zugriff auf record aufgrund von parent permissions. access trait prüft beforeSave nochmal und denied!

Mögliche Lösungen:
---
1. Ordner dürfen nur von einem Owner angelegt werden oder die Fehlermeldung weisst eben darauf hin,
dass es an dieser Stelle schon ein Ordner mit selben Namen existiert?!

2. Access trait mit public property um beforeSave checks zu unterdrücken


