<?php
/**
 * @var yii\web\View&object{
 *   context: hrzg\filefly\controllers\DefaultController
 * } $this
 */

use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Url;


if (class_exists(\hrzg\filemanager\widgets\FileManagerWidget::class)) {

    if (isset(Yii::$app->components['settings'])) {
        $enableThumbnails = Yii::$app->settings->get('enableThumbnails', 'filefly',false);
        $enableIconPreviewView = Yii::$app->settings->get('enableIconPreviewView', 'filefly',false);
    } else {
        $enableThumbnails = false;
        $enableIconPreviewView = false;
    }

    $filemanagerWidgetConfig = [
        'handlerUrl' => Url::to('/' . $this->context->module->id . '/api'),
        'enableThumbnails' =>  $enableThumbnails,
        'enableIconPreviewView' => $enableIconPreviewView,
        // the api has no compress and extract actions, hide them unless enabled explicitly
        'options' => ArrayHelper::merge(
            ['allowedActions' => ['compress' => false, 'compressChooseName' => false, 'extract' => false]],
            !empty($this->context->module->fileManagerWidgetOptions) ? $this->context->module->fileManagerWidgetOptions : []
        ),
    ];

    echo \hrzg\filemanager\widgets\FileManagerWidget::widget(
        $filemanagerWidgetConfig
    );
} else {
    echo Html::tag('p', Yii::t('filefly', 'Filemanager widgets are not available.'));
}
