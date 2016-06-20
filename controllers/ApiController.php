<?php

namespace hrzg\filefly\controllers;

use hrzg\filefly\components\FileManagerApi;
use hrzg\filefly\components\Rest;
use hrzg\filefly\plugins\Permissions;

class ApiController extends \yii\rest\Controller
{

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

    public function actionIndex()
    {
        // set the yii component name of the filesystem
        $fsComponent = 'fsLocal';

        // Manager API
        $fileManagerApi = new FileManagerApi(\Yii::$app->{$fsComponent}, $fsComponent);

        $rest = new Rest();
        $rest->post([$fileManagerApi, 'postHandler'])
            ->get([$fileManagerApi, 'getHandler'])
            ->handle();
    }
}
