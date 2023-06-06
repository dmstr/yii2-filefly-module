<?php
/**
 * @link http://www.herzogkommunikation.de/
 * @copyright Copyright (c) 2017 herzog kommunikation GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace hrzg\filefly\models\base;

use Yii;

/**
 * This is the base-model class for table "filefly_hashmap".
 *
 * @property integer $id
 * @property string $component
 * @property string $type
 * @property string $path
 * @property integer $size
 * @property integer $access_owner
 * @property string $access_read
 * @property string $access_update
 * @property string $access_delete
 * @property string $created_at
 * @property string $updated_at
 *
 * Class FileflyHashmap
 * @package hrzg\filefly\models\base
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
abstract class FileflyHashmap extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'filefly_hashmap';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['component', 'path'], 'required'],
            [['size'], 'integer'],
            [['created_at', 'updated_at'], 'safe'],
            [['component'], 'string', 'max' => 45],
            [['type'], 'string', 'max' => 32],
            // we want to allow int ids and uuid strings, so we do not check strict string type here
            [['access_owner'], 'string', 'max' => 36, 'strict' => false],
            [['access_read', 'access_update', 'access_delete', 'path'], 'string', 'max' => 255],
            [
                ['component', 'path', 'access_owner'],
                'unique',
                'targetAttribute' => ['component', 'path'],
                'message'         => 'The combination of Component, Path has already been taken.'
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id'            => Yii::t('filefly', 'ID'),
            'component'     => Yii::t('filefly', 'Component'),
            'type'          => Yii::t('filefly', 'Type'),
            'path'          => Yii::t('filefly', 'Path'),
            'size'          => Yii::t('filefly', 'Size'),
            'access_owner'  => Yii::t('filefly', 'Access Owner'),
            'access_read'   => Yii::t('filefly', 'Access Read'),
            'access_update' => Yii::t('filefly', 'Access Update'),
            'access_delete' => Yii::t('filefly', 'Access Delete'),
            'created_at'    => Yii::t('filefly', 'Created At'),
            'updated_at'    => Yii::t('filefly', 'Updated At'),
        ];
    }
}
