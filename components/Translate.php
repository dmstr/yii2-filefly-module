<?php
namespace hrzg\filefly\components;

/**
 * Translate class
 *
 * For simple translation to alternative languages
 * @author      Jakub Ďuraš <jakub@duras.me>
 * @author      Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class Translate
{
    private $strings = [];

    public function __construct($lang)
    {
        $langFile = \Yii::getAlias('@hrzg/filefly') . '/messages/' . $lang . '.json';

        // use fallback en language file
        if (!file_exists($langFile)) {
            $langFile = \Yii::getAlias('@hrzg/filefly') . '/messages/en.json';
        }

        $json = file_get_contents($langFile);

        $this->strings = json_decode($json, true);
    }

    public function __get($name)
    {
        if (isset($this->strings[$name])) {
            return $this->strings[$name];
        } else {
            return $name;
        }
    }
}