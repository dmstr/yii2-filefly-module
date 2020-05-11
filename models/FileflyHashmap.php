<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2017 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\models;

use dmstr\activeRecordPermissions\ActiveRecordAccessTrait;
use hrzg\filefly\models\base\FileflyHashmap as BaseFileflyHashmap;
use hrzg\filefly\Module;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use yii\helpers\ArrayHelper;

/**
 * Class FileflyHashmap
 * @package hrzg\filefly\models
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
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

    /**
     * Returns the total file size for all or a specific filesystem component
     *
     * @param bool|false $raw
     * @param string|null $fsComponent
     *
     * @return mixed|string
     */
    public static function getTotalSize($raw = false, $fsComponent = null)
    {
        $query = self::find();
        if (\Yii::$app->has($fsComponent)) {
            $query = $query->andWhere(['component' => $fsComponent]);
        }
        $totalBytes = $query->sum('size');

        if ($totalBytes === null) {
            $totalBytes = 0;
        }

        if ($raw) {
            return $totalBytes;
        } else {
            return \Yii::$app->formatter->asShortSize($totalBytes, 2);
        }
    }
}
