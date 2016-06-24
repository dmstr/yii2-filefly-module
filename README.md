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

Filefly Admins dürfen/können alles!

Solange keine Berechtigungen gesetzt sind, wird down up geschaut ob irgendwo das geforderte Recht gegeben ist.


Sobald auf diesem Weg ein Recht vorhanden -> berechtigt
Sobald auf diesem Weg ein Recht verweigert -> untersagt





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