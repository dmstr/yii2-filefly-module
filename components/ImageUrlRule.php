<?php

namespace hrzg\filefly\components;

use yii\base\BaseObject;
use yii\web\UrlRuleInterface;

/**
 * Class ImageUrlRule
 *
 * Parses URLs in the form /<prefix><delimiter><path><delimiter><action>
 * Example:
 *
 *  http://127.0.0.1/img/stream/folder/example.jpg
 *
 * will be converted to
 *
 *  [ 'filefly/api', ['action => 'stream', 'path' => 'folder/example.jpg'] ]
 *
 * A URL can have an optional numeric part at the end to invalidate caches
 *
 *  /img/stream/header.jpg,p12345
 *
 * @package hrzg\filefly\components
 */
class ImageUrlRule extends BaseObject implements UrlRuleInterface
{
    public $prefix = 'img';
    public $suffix = '';
    public $delimiter = '/';
    public $valid_actions = ['stream', 'download'];
    public $default_action = 'stream';

    public function createUrl($manager, $route, $params)
    {
        return false; // this rule does not apply
    }

    public function parseRequest($manager, $request)
    {
        $pathInfo = $request->getPathInfo();
        if (preg_match('%^('.$this->prefix.')/([^/]*)/(.*)('.$this->suffix.')([0-9]*)$%', $pathInfo, $matches)) {
            $action= in_array($matches[2], $this->valid_actions) ? $matches[2] : $this->default_action;
            return ['filefly/api', ['action' => $action, 'path' => $matches[3]]];
        }
        return false; // this rule does not apply
    }
}