<?php
/*
 * @var yii\web\View $this
 */

use yii\helpers\Inflector;

$this->title = 'Filefly';
?>

<?php $url = \yii\helpers\Url::to($this->context->module->id . '/api', true) ?>
<?php $relativeUrl = '/' . \yii\helpers\Url::to($this->context->module->id . '/api', false) ?>

<h1><?= Inflector::titleize($this->context->module->id) ?></h1>
<hr>

<div class="row">
    <div class="col-xs-12 col-md-6">
        <div class="box box-info">
            <div class="box-header">
                <h3 class="box-title">API Info</h3>
            </div>
            <div class="box-body">
                <?= \Yii::t('filefly', 'Filesystem Component') ?>
                <b><?= $this->context->module->filesystem ?></b>
                <br/>
                <?= \Yii::t('filefly', 'Handler URL') ?>
                <b><?= $url ?></b>
                <hr>
                <p>
                    Recommended configuration
                <pre><code>"urlManager" => [
    "ignoreLanguageUrlPatterns" => [
        "#^<?= preg_quote($this->context->module->id) ?>/api#" => "#^<?= preg_quote($this->context->module->id) ?>/api#"
    ]
]</code></pre>
                </p>
            </div>
        </div>
    </div>


    <div class="col-xs-12 col-md-6">
        <div class="box box-default">
            <div class="box-header">
                <h3 class="box-title">Widget</h3>
            </div>
            <div class="box-body">
<pre><code>{{ use ('hrzg/filemanager/widgets') }}
{{ file_manager_widget_widget({
    "handlerUrl": "<?= $relativeUrl ?>"
}) }}</code></pre>
                <b><?= $url ?></b>
            </div>
        </div>
    </div>
</div>


<div class="row">
    <div class="col-xs-12 col-md-6">
        <div class="box box-success">
            <div class="box-header">
                <h3 class="box-title">RBAC Roles / Permissions</h3>
            </div>
            <div class="box-body">

                API Route (requires RBAC permission)
                <b><?= $this->context->module->id ?>_api_index</b>

                <hr/>

                Other (require RBAC permissions)
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
        </div>
    </div>

    <div class="col-xs-12 col-md-6">
        <div class="box box-success">
            <div class="box-header">
                <h3 class="box-title">Access Fields</h3>
            </div>
            <div class="box-body">
                <?php foreach ($this->context->module->accessFields as $accessField) : ?>
                    <ul>
                        <li><?= $accessField ?></li>
                    </ul>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-xs-12 col-md-12">

        <div class="box box-warning">
            <div class="box-header">
                <h3 class="box-title">Debug</h3>
            </div>
            <div class="box-body">

                <pre><code>curl \
  -H 'Content-Type: application/json;charset=UTF-8' \
  '<?= $url ?>' \
  --data-binary '{"action":"list","path":"/"}'</code></pre>
            </div>
        </div>

    </div>
</div>
