<?php
/**
 * @link http://www.herzogkommunikation.de/
 * @copyright Copyright (c) 2014 herzog kommunikation GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly;

use yii\base\BootstrapInterface;

/**
 * Class Bootstrap
 * @package hrzg\filefly
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class Bootstrap implements BootstrapInterface
{
    /**
     * Register module as `filefly`
     *
     * @param \yii\base\Application $app
     */
    public function bootstrap($app)
    {
        $app->params['yii.migrations'][] = '@vendor/hrzg/yii2-filefly-module/migrations';

        if (!\Yii::$app->hasModule('filefly')) {
            $app->setModule(
                'filefly',
                [
                    'class'  => 'hrzg\filefly\Module',
                    'layout' => '@backend/views/layouts/main',
                ]
            );
        }
    }
}