<?php

namespace hrzg\filefly\components;

use yii\base\Object;
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
 * @package hrzg\filefly\components
 */
class ImageUrlRule extends Object implements UrlRuleInterface
{
    public $prefix = 'img';
    public $suffix = '';
    public $delimiter = '/';
    public $actions = ['stream'];

    public function createUrl($manager, $route, $params)
    {
        return false; // this rule does not apply
    }

    public function parseRequest($manager, $request)
    {
        $pathInfo = $request->getPathInfo();
        if (preg_match('%^('.$this->prefix.')/([^/]*)/(.*)('.$this->suffix.')$%', $pathInfo, $matches)) {
            return ['filefly/api', ['action' => 'stream', 'path' => $matches[3]]];
        }
        return false; // this rule does not apply
    }
}