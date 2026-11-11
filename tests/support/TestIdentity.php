<?php
// file generated with AI assistance: Claude Code - [2026-11-11 12:30:00 UTC]

namespace hrzg\filefly\tests\support;

use yii\base\BaseObject;
use yii\web\IdentityInterface;

/**
 * Minimal identity for the harness, the user component runs without session,
 * so the identity is set directly via `Yii::$app->user->setIdentity()`.
 *
 * `username` is read by \dmstr\web\User to detect root users.
 */
class TestIdentity extends BaseObject implements IdentityInterface
{
    /**
     * @var int|string
     */
    public $id;

    /**
     * @var string
     */
    public $username;

    public static function findIdentity($id)
    {
        return null;
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        return null;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getAuthKey()
    {
        return null;
    }

    public function validateAuthKey($authKey)
    {
        return false;
    }
}
