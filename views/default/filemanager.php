<?php

use yii\helpers\Html;
use yii\helpers\Url;


if (class_exists(\hrzg\filemanager\widgets\FileManagerWidget::class)) {

    if (isset(Yii::$app->components['settings'])) {
        $thumbnailUrlPrefix = Yii::$app->settings->get('thumbnailUrlPrefix', 'filefly','');
        $thumbnailUrlSuffix = Yii::$app->settings->get('thumbnailUrlSuffix', 'filefly','');
    } else {
        $thumbnailUrlPrefix = '';
        $thumbnailUrlSuffix = '';
    }

    echo \hrzg\filemanager\widgets\FileManagerWidget::widget(
        [
            'handlerUrl' => Url::to('/' . $this->context->module->id . '/api'),
            'thumbnailUrlPrefix' => $thumbnailUrlPrefix,
            'thumbnailUrlSuffix' => $thumbnailUrlSuffix,
        ]
    );
} else {
    echo Html::tag('p', Yii::t('filefly', 'Filemanager widgets are not available.'));
}

