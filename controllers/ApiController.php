<?php

namespace hrzg\filefly\controllers;

use hrzg\filefly\models\FileManagerApi;
use hrzg\filefly\models\Rest;

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

        $fileManagerApi = new FileManagerApi(\Yii::$app->fs);

        $rest = new Rest();
        $rest->post([$fileManagerApi, 'postHandler'])
            ->get([$fileManagerApi, 'getHandler'])
            ->handle();
    }

}
