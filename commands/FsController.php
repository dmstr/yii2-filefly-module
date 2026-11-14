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
     * @var FsManager
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
            $contents = $this->manager->listItems($uri, $this->recursive);
            foreach ($contents as $file) {
                if ($this->long) {


                    $line = sprintf("%s  %s\t%s\t%s",
                        substr($file->getType(), 0, 1),
                        str_pad($file->getFileSize() ?? 'n/a', 10, " "),
                        str_pad($file->getLastModified() ?? 'n/a', 10, " "),
                        $file->getPath());
                    $this->stdout("{$line}\n");
                } else {
                    $this->stdout(str_pad((string)($file->getPath()), 10, " ") . "\t");
                }
            }
            $this->stdout("\n");
        } catch (\RuntimeException $e) {
            // includes StorageException, e.g. for a link the adapter refuses
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

        $success = $this->manager->createDirectory($uri);

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
     * @throws \hrzg\filefly\components\storage\ItemExistsException
     * @return int
     */
    public function actionCp($src, $dest)
    {

        $this->manager->mount($this->parseScheme($src));
        $this->manager->mount($this->parseScheme($dest));

        $success = $this->manager->copyFile($src, $dest);

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
     * @throws \hrzg\filefly\components\storage\ItemNotFoundException
     * @return int
     */
    public function actionRm($uri)
    {
        if ($this->confirm("Do you want delete the file '$uri' ?")) {
            $this->manager->mount($this->parseScheme($uri));
            $success = $this->manager->deleteFile($uri);
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
            $success = $this->manager->deleteDirectory($uri);
            if (!$success) {
                $this->stderr('Deletion of directory failed');
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }
        return ExitCode::OK;
    }

    /**
     * Synchronize source to destination: copies missing files and replaces target files that are
     * older than their source (`+` for both, `.` for a skipped file); see FsManager::sync()
     *
     * @param $src
     * @param $dest
     *
     * @throws \hrzg\filefly\components\storage\ItemExistsException
     * @throws \hrzg\filefly\components\storage\ItemNotFoundException
     * @return int
     */
    public function actionSync($src, $dest)
    {

        $manager = $this->manager;

        $this->manager->mount($this->parseScheme($src));
        $this->manager->mount($this->parseScheme($dest));

        $srcScheme = $manager->schemeOf($src);
        $contents = $manager->listItems($src, $this->recursive);

        if (!$this->confirm('Sync ' . count($contents) . ' files(s)?')) {
            return ExitCode::OK;
        }

        foreach ($contents as $entry) {
            $update = false;
            $replace = false;
            $srcUrl = $srcScheme . '://' . $entry->getPath();
            $destUrl = $dest . $entry->getPath();

            if ($entry->getType() === 'dir') {
                $this->stdout("Skipped directory '{$entry->getPath()}'\n");
                continue;
            }

            if (!$manager->exists($destUrl)) {
                $update = true;
            } else if ($manager->lastModified($srcUrl) > $manager->lastModified($dest . $entry->getPath())) {
                $update = true;
                $replace = true;
            }

            if ($update) {
                if ($replace) {
                    // replaces an older target file; a directory at the target is refused (throws)
                    $manager->replaceFile($srcUrl, $destUrl);
                } else {
                    $manager->copyFile($srcUrl, $destUrl);
                }
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
