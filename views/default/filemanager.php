<?php

use yii\helpers\Html;
use yii\helpers\Url;


if (class_exists(\hrzg\filemanager\widgets\FileManagerWidget::class)) {

    if (isset(Yii::$app->components['settings'])) {
        $thumbnailUrlPrefix = Yii::$app->settings->get('thumbnailUrlPrefix', 'filefly','');
        $thumbnailUrlSuffix = Yii::$app->settings->get('thumbnailUrlSuffix', 'filefly','');
        $enableThumbnails = Yii::$app->settings->get('enableThumbnails', 'filefly',false);
    } else {
        $thumbnailUrlPrefix = '';
        $thumbnailUrlSuffix = '';
        $enableThumbnails = false;
    }

    echo \hrzg\filemanager\widgets\FileManagerWidget::widget(
        [
            'handlerUrl' => Url::to('/' . $this->context->module->id . '/api'),
            'thumbnailUrlPrefix' => $thumbnailUrlPrefix,
            'thumbnailUrlSuffix' => $thumbnailUrlSuffix,
            'enableThumbnails' => $enableThumbnails
        ]
    );
} else {
    echo Html::tag('p', Yii::t('filefly', 'Filemanager widgets are not available.'));
}

