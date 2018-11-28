<?php
/**
 * @link http://www.herzogkommunikation.de/
 * @copyright Copyright (c) 2017 herzog kommunikation GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace hrzg\filefly;

use creocoder\flysystem\Filesystem;
use dmstr\web\traits\AccessBehaviorTrait;
use hrzg\filefly\helpers\FsManager;
use yii\web\HttpException;

/**
 * Class Module
 * @package hrzg\filefly
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class Module extends \yii\base\Module
{
    use AccessBehaviorTrait;

    const NAME = 'filefly';

    /**
     * Access fields
     */
    const ACCESS_OWNER = 'access_owner';
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
    public $accessFields = [self::ACCESS_OWNER, self::ACCESS_READ, self::ACCESS_UPDATE, self::ACCESS_DELETE];

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
     * @var \creocoder\flysystem\Filesystem default filesystem
     */
    public $filesystemComponent;

    /**
     * @var array mapping for filesystems 'scheme' => 'component'
     */
    public $filesystemComponents = [];

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
     * Active / deactivate the filesystem and hashmap self healing plugins
     * @var bool
     */
    public $repair = true;

    /**
     * Active / deactivate the filesystem recursive folder delete
     * @var bool
     */
    public $deleteRecursive = false;

    /**
     * Used as default permissions on active \hrzg\filefly\plugins\RepairKit
     * if $this->repair = true
     *
     * @var array
     */
    public $defaultPermissions = [
        self::ACCESS_OWNER  => 1,
        self::ACCESS_READ   => '*',
        self::ACCESS_UPDATE => '*',
        self::ACCESS_DELETE => '*',
    ];


    /**
     * Offset (in seconds) for Expires Header in stream action, default: 1 week
     * @var int
     */
    public $streamExpireOffset = 604800;

    private $_manager;

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

        // breadcrumbs
        \Yii::$app->controller->view->params['breadcrumbs'][] = ['label' => 'Filefly module', 'url' => ['/'.$this->module->id]];

        return true;
    }

    public function getManager(){
        if (!$this->_manager) {
            $this->_manager = new FsManager();
            $this->_manager->setModule($this);
        }
        return $this->_manager;
    }

}
