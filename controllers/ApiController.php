<?php
/**
 * @link http://www.herzogkommunikation.de/
 * @copyright Copyright (c) 2017 herzog kommunikation GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\controllers;

use hrzg\filefly\components\FileManagerApi;
use hrzg\filefly\components\Rest;
use hrzg\filefly\Module;
use Yii;
use yii\filters\AccessControl;
use yii\filters\Cors;
use yii\rest\Controller;
use yii\web\HttpException;
use yii\web\Response as WebResponse;

/**
 * Class ApiController
 *
 * @package hrzg\filefly\controllers
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 *
 * @property Module $module
 */
class ApiController extends Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        $behaviors = parent::behaviors();

        $behaviors['corsFilter'] = [
            'class' => Cors::class,
            'cors' => [
                'Origin' => ['*'],
                'Access-Control-Request-Method' => [
                    'GET',
                    'POST',
                    'PUT',
                    'PATCH',
                    'DELETE',
                    'HEAD',
                    'OPTIONS'
                ],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => false,
                'Access-Control-Max-Age' => 86400,
            ],
        ];
        $behaviors['access'] = [
            'class' => AccessControl::class,
            'rules' => [
                [
                    'allow' => true,
                    'matchCallback' => function ($rule, $action) {
                        return Yii::$app->user->can(
                            $this->module->id . '_' . $this->id . '_' . $action->id,
                            ['route' => true]
                        );
                    },
                ]
            ]
        ];

        return $behaviors;
    }

    /**
     * @inheritdoc
     */
    public function actionIndex($scope = null)
    {
        // Manager API
        $fileManagerApi = new FileManagerApi($this->module->filesystemComponent, $this->module->filesystem, $this->module, $scope);

        try {
            $rest = new Rest();
            $rest->post([$fileManagerApi, 'postHandler'])
                ->get([$fileManagerApi, 'getHandler'])
                ->handle();
        } catch (HttpException $e) {
            Yii::$app->response->format = WebResponse::FORMAT_HTML;
            throw new HttpException($e->statusCode, $e->getMessage());
        }

    }
}
