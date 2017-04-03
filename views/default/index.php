<?php
/*
 * @var yii\web\View $this
 */
use yii\helpers\Inflector;

$this->title = 'Filefly';
?>
<h1><?= Inflector::titleize($this->context->module->id) ?></h1>
<hr>
<div class="box box-default">
    <div class="container">
        <div class="row">
            <div class="col-xs-12 col-md-4">
                <h4>
                    <?= \Yii::t('filefly', 'Current Filesystem') ?>
                </h4>
                <ul>
                    <li><?= Inflector::titleize($this->context->module->filesystem) ?></li>
                </ul>
            </div>
            <div class="col-xs-12 col-md-4">
                <h4>RBAC Roles / Permissions</h4>
                <?php foreach ($this->context->module->accessRoles as $accessRole => $accessPermissions) : ?>
                    <ul>
                        <li>
                            <?= $accessRole ?>
                            <ul>
                                <?php foreach ($accessPermissions as $accessPermission) : ?>
                                    <li><?= $accessPermission ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    </ul>
                <?php endforeach; ?>
            </div>
            <div class="col-xs-12 col-md-4">
                <h4>Access Fields</h4>
                <?php foreach ($this->context->module->accessFields as $accessField) : ?>
                    <ul>
                        <li><?= $accessField ?></li>
                    </ul>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
