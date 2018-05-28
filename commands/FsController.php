<?php

namespace hrzg\filefly\commands;

/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2017 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use hrzg\filefly\helpers\FsManager;
use League\Flysystem\MountManager;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;

class FsController extends \yii\console\Controller
{

    public $recursive = false;
    public $long = false;

    /**
     * @var $manager FsManager
     */
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
        $this->manager = new FsManager();
        $this->manager->setModule(\Yii::$app->getModule('filefly'));
    }

    /**
     * Show configured filesystems
     */
    public function actionIndex()
    {
        $this->stdout("FlyCLI\n", Console::FG_BLUE, Console::UNDERLINE);
        $this->stdout("\n");
        foreach ($this->manager->getModule()->filesystemComponents as $scheme => $component) {
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
        $this->manager->mount($this->parseScheme($uri));

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
        $this->manager->mount($this->parseScheme($uri));

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

        $this->manager->mount($this->parseScheme($src));
        $this->manager->mount($this->parseScheme($dest));

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

        $this->manager->mount($this->parseScheme($src));
        $this->manager->mount($this->parseScheme($dest));

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
            $this->manager->mount($this->parseScheme($uri));
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
            $this->manager->mount($this->parseScheme($uri));
            $this->manager->deleteDir($uri);
        }
    }

    /**
     * Synchronize source to destination
     *
     * @param $src
     * @param $dest
     */
    public function actionSync($src, $dest)
    {
        /**
         * @var $manager \League\Flysystem\MountManager
         */
        $manager = $this->manager;

        $this->manager->mount($this->parseScheme($src));
        $this->manager->mount($this->parseScheme($dest));

        $contents = $manager->listContents($src, $this->recursive);

        if (!$this->confirm('Sync '.count($contents).' files(s)?')) {
            return;
        }

        foreach ($contents as $entry) {
            $update = false;
            $srcUrl = $entry['filesystem'].'://'.$entry['path'];
            $destUrl = $dest.$entry['path'];

            if ($entry['type'] == 'dir') {
                $this->stdout("Skipped directory '{$entry['path']}'\n");
                continue;
            }

            if (!$manager->has($destUrl)) {
                $update = true;
            } elseif ($manager->getTimestamp($srcUrl) > $manager->getTimestamp($dest.$entry['path'])) {
                $update = true;
            }

            if ($update) {
                $manager->copy($srcUrl, $destUrl);
                $this->stdout("+");
                #$this->stdout($srcUrl."\n");
            } else {
                $this->stdout('.');
            }
        }
        $this->stdout("\nDone.\n");
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
