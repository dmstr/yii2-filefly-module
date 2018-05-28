<?php

namespace hrzg\filefly\helpers;

/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2017 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
class FsManager extends \League\Flysystem\MountManager
{
    private $_module;
    public function setModule($module)
    {
        $this->_module = $module;
    }
    public function getModule()
    {
        return $this->_module;
    }

    public function mount($fs)
    {
        $component = $this->getModule()->filesystemComponents[$fs];
        $this->mountFilesystem($fs, \Yii::$app->{$component}->getFilesystem());
    }

    public function sync($src, $dest, $recursive)
    {
        /**
         * @var $manager \League\Flysystem\MountManager
         */
        $manager = $this;

        $this->mount($this->parseScheme($src));
        $this->mount($this->parseScheme($dest));

        $contents = $manager->listContents($src, $recursive);
        \Yii::trace($contents, __METHOD__);
        foreach ($contents as $entry) {
            $update = false;
            $srcUrl = $entry['filesystem'].'://'.$entry['path'];
            $destUrl = $dest.$entry['path'];

            if ($entry['type'] == 'dir') {
                \Yii::trace("Skipped directory '{$entry['path']}'");
                continue;
            }

            if (!$manager->has($destUrl)) {
                $update = true;
            } elseif ($manager->getTimestamp($srcUrl) > $manager->getTimestamp($dest.$entry['path'])) {
                $update = true;
            }

            if ($update) {
                $manager->copy($srcUrl, $destUrl);
                \Yii::trace("copied file $srcUrl to $destUrl");
                #$this->stdout($srcUrl."\n");
            } else {
                \Yii::trace("skipped file $srcUrl");
            }
        }
        \Yii::trace("sync completed");
    }

    private function parseScheme($uri = '/')
    {
        $parts = explode(':', $uri);
        return $parts[0];
    }
}