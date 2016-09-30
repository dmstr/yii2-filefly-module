<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2016 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\controllers;


use dmstr\web\traits\AccessBehaviorTrait;
use yii\web\Controller;

class TestController extends Controller
{
    use AccessBehaviorTrait;
    
    public function actionIndex(){
        return $this->render('index');
    }
}