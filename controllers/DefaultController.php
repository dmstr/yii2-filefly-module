<?php
/**
 * @link http://www.herzogkommunikation.de/
 * @copyright Copyright (c) 2017 herzog kommunikation GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace hrzg\filefly\controllers;

use yii\web\Controller;

/**
 * Class DefaultController
 * @package hrzg\filefly\controllers
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class DefaultController extends Controller
{
    public function actionIndex()
    {
        return $this->render('index');
    }

    public function actionFilemanager()
    {
        return $this->render('filemanager');
    }

    public function actionFilemanagerFullScreen()
    {
        $this->layout = "plain";
        return $this->render('filemanager');
    }
}
