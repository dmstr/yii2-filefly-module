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
use yii\console\ExitCode;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;

class FsController extends \yii\console\Controller
{

    public $recursive = false;
    public $long = false;

    /**
     * @var $manager FsManager
     */
    private $manager;

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
            $this->stdout(str_pad($scheme . "://", 10, ' ') . "\t" . $component . "\n");
        }
        return ExitCode::OK;
    }

    /**
     * List filesystem contents
     *
     * @param $uri
     *
     * @throws \Exception
     * @return int
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
                    $this->stdout(str_pad((string)($file['path']), 10, " ") . "\t");
                }
            }
            $this->stdout("\n");
        } catch (\RuntimeException $e) {
            $this->stderr("{$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        return ExitCode::OK;
    }

    /**
     * Create directory
     *
     * @param $uri
     *
     * @return int
     */
    public function actionMkdir($uri)
    {
        $this->manager->mount($this->parseScheme($uri));

        $success = $this->manager->createDir($uri);

        if (!$success) {
            $this->stderr('Creation of directory failed');
            return ExitCode::UNSPECIFIED_ERROR;
        }
        return ExitCode::OK;
    }

    /**
     * Copy file from source to destination
     *
     * @param $src
     * @param $dest
     *
     * @throws \League\Flysystem\FileExistsException
     * @return int
     */
    public function actionCp($src, $dest)
    {

        $this->manager->mount($this->parseScheme($src));
        $this->manager->mount($this->parseScheme($dest));

        $success = $this->manager->copy($src, $dest);

        if (!$success) {
            $this->stderr('Copy failed');
            return ExitCode::UNSPECIFIED_ERROR;
        }
        return ExitCode::OK;
    }

    /**
     * Move file from source to destination
     *
     * @param $src
     * @param $dest
     *
     * @return int
     */
    public function actionMv($src, $dest)
    {

        $this->manager->mount($this->parseScheme($src));
        $this->manager->mount($this->parseScheme($dest));

        $success = $this->manager->move($src, $dest);

        if (!$success) {
            $this->stderr('Move failed');
            return ExitCode::UNSPECIFIED_ERROR;
        }
        return ExitCode::OK;
    }

    /**
     * Remove file
     *
     * @param $uri
     *
     * @throws \League\Flysystem\FileNotFoundException
     * @return int
     */
    public function actionRm($uri)
    {
        if ($this->confirm("Do you want delete the file '$uri' ?")) {
            $this->manager->mount($this->parseScheme($uri));
            $success = $this->manager->delete($uri);
            if (!$success) {
                $this->stderr('Deletion failed');
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }
        return ExitCode::OK;
    }

    /**
     * Remove directory
     *
     * @param $uri
     *
     * @return int
     */
    public function actionRmdir($uri)
    {
        if ($this->confirm("Do you want delete the directory '$uri' ?")) {
            $this->manager->mount($this->parseScheme($uri));
            $success = $this->manager->deleteDir($uri);
            if (!$success) {
                $this->stderr('Deletion of directory failed');
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }
        return ExitCode::OK;
    }

    /**
     * Synchronize source to destination
     *
     * @param $src
     * @param $dest
     *
     * @throws \League\Flysystem\FileExistsException
     * @throws \League\Flysystem\FileNotFoundException
     * @return int
     */
    public function actionSync($src, $dest)
    {

        $manager = $this->manager;

        $this->manager->mount($this->parseScheme($src));
        $this->manager->mount($this->parseScheme($dest));

        $contents = $manager->listContents($src, $this->recursive);

        if (!$this->confirm('Sync ' . count($contents) . ' files(s)?')) {
            return ExitCode::OK;
        }

        foreach ($contents as $entry) {
            $update = false;
            $srcUrl = $entry['filesystem'] . '://' . $entry['path'];
            $destUrl = $dest . $entry['path'];

            if ($entry['type'] === 'dir') {
                $this->stdout("Skipped directory '{$entry['path']}'\n");
                continue;
            }

            if (!$manager->has($destUrl)) {
                $update = true;
            } else if ($manager->getTimestamp($srcUrl) > $manager->getTimestamp($dest . $entry['path'])) {
                $update = true;
            }

            if ($update) {
                $manager->copy($srcUrl, $destUrl);
                $this->stdout("+");
            } else {
                $this->stdout('.');
            }
        }
        $this->stdout("\nDone.\n");
        return ExitCode::OK;
    }

    private function parseScheme($uri = '/')
    {
        $parts = explode(':', $uri);
        return $parts[0];
    }
}
