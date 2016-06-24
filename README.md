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

Auf das Root eines Filesystems gibt es 1...n berechtigte Admins.

Von ihm können dort Ordner angelegt und Berechtigungen für andere User gesetzt werden.




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