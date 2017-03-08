<?php

namespace hrzg\filefly\models;

use dmstr\db\traits\ActiveRecordAccessTrait;
use hrzg\filefly\models\base\FileflyHashmap as BaseFileflyHashmap;
use hrzg\filefly\Module;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use yii\helpers\ArrayHelper;

/**
 * This is the model class for table "filefly_hashmap".
 */
class FileflyHashmap extends BaseFileflyHashmap
{
    use ActiveRecordAccessTrait;

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return ArrayHelper::merge(
            parent::behaviors(),
            [
                'timestamp' => [
                    'class'              => TimestampBehavior::className(),
                    'createdAtAttribute' => 'created_at',
                    'updatedAtAttribute' => 'updated_at',
                    'value'              => new Expression('NOW()'),
                ]
            ]
        );
    }

    /**
     * @return array with access field names
     */
    public static function accessColumnAttributes()
    {
        return [
            'owner'  => 'access_owner',
            'read'   => 'access_read',
            'update' => 'access_update',
            'delete' => 'access_delete',
            'domain' => false,
        ];
    }

    /**
     * @return array
     */
    public static function accessDefaults()
    {
        return \Yii::$app->getModule(Module::NAME)->defaultPermissions;
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return ArrayHelper::merge(
            parent::rules(),
            [
                [['path'], 'pathValidator'],
            ]
        );
    }

    /**
     * Ensure exact one directory separator at the beginning of the path name
     *
     * @param $attribute
     * @param $params
     */
    public function pathValidator($attribute, $params)
    {
        $val              = ltrim($this->$attribute, DIRECTORY_SEPARATOR);
        $this->$attribute = DIRECTORY_SEPARATOR . $val;
    }
}
