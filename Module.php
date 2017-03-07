<?php

namespace hrzg\filefly;

use creocoder\flysystem\Filesystem;
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
     * @var object creocoder\flysystem\Filesystem
     */
    public $filesystemComponent;

    /**
     * @inheritdoc
     */
    public $controllerNamespace = 'hrzg\filefly\controllers';

    /**
     * use \yii\helpers\Inflector::slug() for file names on create/upload
     * @var bool
     */
    public $slugNames = true;

    /**
     * @inheritdoc
     *
     * @throws HttpException
     */
    public function beforeAction($action)
    {
        parent::beforeAction($action);

        if (empty($this->filesystem)) {
            \Yii::$app->session->addFlash(
                'warning',
                'No filesystem configured for <code>filefly</code> module'
            );
            \Yii::warning('Filesystem not configured.', __METHOD__);
        } else {
            // set the yii component name of the filesystem
            $fsComponentName = $this->filesystem;

            // get the component object
            $this->filesystemComponent = \Yii::$app->{$fsComponentName};

            if ( ! $this->filesystemComponent instanceof Filesystem) {
                \Yii::$app->session->addFlash(
                    'error',
                    'Filesystem component is no instance of creocoder\flysystem\Filesystem'
                );
                \Yii::error('Invalid filesystem component.', __METHOD__);
            }
        }

        return true;
    }
}
