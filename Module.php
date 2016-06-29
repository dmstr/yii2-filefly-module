<?php

namespace hrzg\filefly;

use dmstr\web\traits\AccessBehaviorTrait;
use yii\web\HttpException;

class Module extends \yii\base\Module
{
    use AccessBehaviorTrait;

    /**
     * Access fields
     */
    const ACCESS_READ = 'access_read';
    const ACCESS_UPDATE = 'access_update';
    const ACCESS_DELETE = 'access_delete';

    /**
     * Module roles
     */
    const ACCESS_ROLE_ADMIN = 'FileflyAdmin';
    const ACCESS_ROLE_DEFAULT = 'FileflyDefault';
    const ACCESS_ROLE_API = 'FileflyApi';
    const ACCESS_ROLE_PERMISSIONS = 'FileflyPermissions';

    /**
     * Module permissions
     */
    const ACCESS_PERMISSION_ADMIN = 'filefly';
    const ACCESS_PERMISSION_DEFAULT = 'filefly_default_index';
    const ACCESS_PERMISSION_API = 'filefly_api_index';

    /**
     * @var array
     */
    public $accessFields = [self::ACCESS_READ, self::ACCESS_UPDATE, self::ACCESS_DELETE];

    /**
     * @var array
     */
    public $accessRoles = [
        self::ACCESS_ROLE_ADMIN       => [self::ACCESS_PERMISSION_ADMIN],
        self::ACCESS_ROLE_DEFAULT     => [self::ACCESS_PERMISSION_DEFAULT],
        self::ACCESS_ROLE_API         => [self::ACCESS_PERMISSION_API],
        self::ACCESS_ROLE_PERMISSIONS => [],
    ];

    /**
     * The name of the filesystem component
     * @var string
     */
    public $filesystem;

    /**
     * @var string
     */
    public $controllerNamespace = 'hrzg\filefly\controllers';

    /**
     * @inheritdoc
     *
     * @throws HttpException
     */
    public function init()
    {
        parent::init();

        if (empty($this->filesystem)) {
            \Yii::error('Please configure a filesystem', __METHOD__);
            throw new HttpException(406);
        }
    }
}
