<?php
$this->context->layout = '@backend/views/layouts/box';
?>

<?php if (class_exists(\hrzg\filemanager\widgets\FileManagerWidget::class)): ?>

<?= \hrzg\filemanager\widgets\FileManagerWidget::widget(
    ['handlerUrl' => \yii\helpers\Url::to('/' . $this->context->module->id . '/api')]
) ?>

<?php else : ?>

Filemanager widgets are not available.

<?php endif; ?>
