<?php

use yii\helpers\Html;
use yii\helpers\Url;


if (class_exists(\hrzg\filemanager\widgets\FileManagerWidget::class)) {

    if (isset(Yii::$app->components['settings'])) {
        $thumbnailUrlPrefix = Yii::$app->settings->get('thumbnailUrlPrefix', 'filefly','');
        $thumbnailUrlSuffix = Yii::$app->settings->get('thumbnailUrlSuffix', 'filefly','');
        $enableThumbnails = Yii::$app->settings->get('enableThumbnails', 'filefly',0);
        $enableIconPreviewView = Yii::$app->settings->get('enableIconPreviewView', 'filefly',0);
    } else {
        $thumbnailUrlPrefix = '';
        $thumbnailUrlSuffix = '';
        $enableThumbnails = 0;
        $enableIconPreviewView = 0;
    }

    echo \hrzg\filemanager\widgets\FileManagerWidget::widget(
        [
            'handlerUrl' => Url::to('/' . $this->context->module->id . '/api'),
            'thumbnailUrlPrefix' => $thumbnailUrlPrefix,
            'thumbnailUrlSuffix' => $thumbnailUrlSuffix,
            'enableThumbnails' =>  $enableThumbnails,
            'enableIconPreviewView' => $enableIconPreviewView,
        ]
    );
} else {
    echo Html::tag('p', Yii::t('filefly', 'Filemanager widgets are not available.'));
}

