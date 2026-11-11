<?php
// file generated with AI assistance: Claude Code - [2026-11-11 12:30:00 UTC]

namespace hrzg\filefly\tests\support;

use yii\base\Component;

/**
 * In-memory stand-in for the `settings` component of pheme/yii2-settings.
 *
 * The module calls `Yii::$app->settings->getOrSet()` in Module::beforeAction() and
 * `Yii::$app->settings->get()` in ApiController::actionUpload() (key `mime-whitelist`, section `filefly`),
 * but does not declare the package as dependency. Only the methods used by the module are implemented,
 * with the same signatures and return values as pheme\settings\components\Settings.
 */
class TestSettings extends Component
{
    /**
     * @var array [section => [key => value]]
     */
    private $_data = [];

    public function get($key, $section = null, $default = null)
    {
        [$key, $section] = $this->splitKey($key, $section);
        return array_key_exists($key, $this->_data[$section] ?? []) ? $this->_data[$section][$key] : $default;
    }

    public function set($key, $value, $section = null, $type = null)
    {
        [$key, $section] = $this->splitKey($key, $section);
        $this->_data[$section][$key] = $value;
        return true;
    }

    public function has($key, $section = null)
    {
        [$key, $section] = $this->splitKey($key, $section);
        return array_key_exists($key, $this->_data[$section] ?? []);
    }

    public function getOrSet($key, $value, $section = null, $type = null)
    {
        if ($this->has($key, $section)) {
            return $this->get($key, $section);
        }
        return $this->set($key, $value, $section, $type);
    }

    public function delete($key, $section = null)
    {
        [$key, $section] = $this->splitKey($key, $section);
        unset($this->_data[$section][$key]);
        return true;
    }

    private function splitKey($key, $section)
    {
        if ($section === null) {
            $pieces = explode('.', $key, 2);
            return count($pieces) > 1 ? [$pieces[1], $pieces[0]] : [$key, ''];
        }
        return [$key, $section];
    }
}
