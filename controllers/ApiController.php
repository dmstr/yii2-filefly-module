<?php

namespace hrzg\filefly\controllers;

use creocoder\flysystem\Filesystem;
use hrzg\filefly\components\FileManagerApi;
use hrzg\filefly\components\Rest;
use hrzg\filefly\plugins\Permissions;
use yii\web\HttpException;

class ApiController extends \yii\rest\Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        $behaviors               = parent::behaviors();
        $behaviors['corsFilter'] = [
            'class' => \yii\filters\Cors::className(),
            'cors'  => [
                'Origin'                           => ['*'],
                'Access-Control-Request-Method'    => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                'Access-Control-Request-Headers'   => ['*'],
                'Access-Control-Allow-Credentials' => true,
                'Access-Control-Max-Age'           => 86400,
            ],
        ];
        return $behaviors;
    }

    /**
     * @inheritdoc
     */
    public function actionIndex()
    {
        // set the yii component name of the filesystem
        $fsComponentName = $this->module->filesystem;

        $fsComponent = \Yii::$app->{$fsComponentName};
        if (!$fsComponent instanceof Filesystem) {
            throw new HttpException(500, 'Filesystem component is no instance of creocoder\flysystem\Filesystem');
        }

        // Manager API
        $fileManagerApi = new FileManagerApi($fsComponent, $fsComponentName);

        $rest = new Rest();
        $rest->post([$fileManagerApi, 'postHandler'])
            ->get([$fileManagerApi, 'getHandler'])
            ->handle();
    }
}
