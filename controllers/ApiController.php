<?php

namespace hrzg\filefly\controllers;

use yii\web\HttpException;

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
        switch (true) {
            case 'list':
                $contents = \Yii::$app->fs->listContents();
                $result   = [];
                foreach ($contents AS $file) {
                    $result[] = [
                        'name' => $file['filename'],
                        'date' => date('Y-m-d H:m:i', $file['timestamp']),
                        'type' => $file['type'],
                        'size' => (array_key_exists('size', $file)) ? $file['size'] : null,
                    ];
                }
                return ['result' => $result];
                break;
            default:
                throw new HttpException('Not implemented.');
        }
    }

}
