<?php

namespace hrzg\filefly\models;

use dmstr\db\traits\ActiveRecordAccessTrait;
use Yii;
use \hrzg\filefly\models\base\FileflyHashmap as BaseFileflyHashmap;

/**
 * This is the model class for table "filefly_hashmap".
 */
class FileflyHashmap extends BaseFileflyHashmap
{
    use ActiveRecordAccessTrait;
}
