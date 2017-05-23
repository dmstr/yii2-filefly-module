<?php

namespace hrzg\filefly\commands;

/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2017 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use League\Flysystem\MountManager;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;

class FsController extends \yii\console\Controller
{
    public $filesystemComponents = [];

    public $recursive = false;
    public $long = false;

    private $manager = null;

    public function options($actionID)
    {
        return ArrayHelper::merge(

            parent::options($actionID),
            [
                'long',
                'recursive',
            ]
        );
    }

    public function optionAliases()
    {
        return ArrayHelper::merge(

            parent::optionAliases(),
            [
                'l' => 'long',
                'r' => 'recursive',
            ]
        );
    }

    public function init()
    {
        $this->manager = new MountManager();
    }

    /**
     * Show configured filesystems
     */
    public function actionIndex()
    {
        $this->stdout("FlyCLI\n", Console::FG_BLUE, Console::UNDERLINE);
        $this->stdout("\n");
        foreach ($this->filesystemComponents as $scheme => $component) {
            $this->stdout(str_pad($scheme."://", 10, ' ')."\t".$component);
            $this->stdout("\n");
        }

    }

    /**
     * List filesystem contents
     *
     * @param $uri
     */
    public function actionLs($uri)
    {
        $this->mount($this->parseScheme($uri));

        try {
            $contents = $this->manager->listContents($uri, $this->recursive);
            foreach ($contents as $file) {
                if ($this->long) {


                    $line = sprintf("%s  %s\t%s\t%s",
                        substr($file['type'], 0, 1),
                        str_pad(ArrayHelper::getValue($file, 'size', 'n/a'), 10, " "),
                        str_pad(ArrayHelper::getValue($file, 'timestamp', 'n/a'), 10, " "),
                        $file['path']);
                    $this->stdout("{$line}\n");
                } else {
                    $this->stdout(str_pad("{$file['path']}", 10, " ")."\t");
                }
            }
            $this->stdout("\n");
        } catch (\RuntimeException $e) {
            $this->stderr("{$e->getMessage()}\n", Console::FG_RED);
        }

    }

    /**
     * Create directory
     *
     * @param $uri
     */
    public function actionMkdir($uri)
    {
        $this->mount($this->parseScheme($uri));

        $this->manager->createDir($uri);
    }

    /**
     * Copy file from source to destination
     *
     * @param $src
     * @param $dest
     */
    public function actionCp($src, $dest)
    {

        $this->mount($this->parseScheme($src));
        $this->mount($this->parseScheme($dest));

        $success = $this->manager->copy($src, $dest);

        if (!$success) {
            $this->stderr('Copy failed');
        }
    }

    /**
     * Move file from source to destination
     *
     * @param $src
     * @param $dest
     */
    public function actionMv($src, $dest)
    {

        $this->mount($this->parseScheme($src));
        $this->mount($this->parseScheme($dest));

        $success = $this->manager->move($src, $dest);

        if (!$success) {
            $this->stderr('Move failed');
        }
    }

    /**
     * Remove file
     *
     * @param $uri
     */
    public function actionRm($uri)
    {
        if ($this->confirm("Do you want delete the file '$uri' ?")) {
            $this->mount($this->parseScheme($uri));
            $this->manager->delete($uri);
        }
    }

    /**
     * Remove directory
     *
     * @param $uri
     */
    public function actionRmdir($uri)
    {
        if ($this->confirm("Do you want delete the directory '$uri' ?")) {
            $this->mount($this->parseScheme($uri));
            $this->manager->deleteDir($uri);
        }
    }

    /**
     * Synchronize source to destination
     * @param $src
     * @param $dest
     */
    public function actionSync($src, $dest)
    {
        $manager = $this->manager;

        $this->mount($this->parseScheme($src));
        $this->mount($this->parseScheme($dest));

        $contents = $manager->listContents($src, false);

        foreach ($contents as $entry) {
            $update = false;

            if (is_array($entry['path'])) {
                $this->stdout("Skipped directory '{$entry['path']}'");
                continue;
            }
            if (!$manager->has($dest.$entry['path'])) {
                $update = true;
            } elseif ($manager->getTimestamp($src.$entry['path']) > $manager->getTimestamp($dest.$entry['path'])) {
                $update = true;
            }

            if ($update) {
                $manager->copy($src.$entry['path'], $dest.$entry['path']);
                $this->stdout($entry['path']."\n");
            } else {
                $this->stdout('.');
            }
        }
    }


    private function mount($fs)
    {
        $component = $this->filesystemComponents[$fs];
        $this->manager->mountFilesystem($fs, \Yii::$app->{$component}->getNativeFilesystem());
    }

    private function parseScheme($uri = '/')
    {
        $parts = explode(':', $uri);
        return $parts[0];
    }

    private function parseLocation($uri = '/')
    {
        $parts = explode(':', $uri);
        return $parts[1];
    }
}
