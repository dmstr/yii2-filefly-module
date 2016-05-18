<?php
namespace hrzg\filefly\models;

use creocoder\flysystem\Filesystem;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Util;

/**
 * Class FileManagerApi
 * @package hrzg\filefly\models
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 *
 * Filesystem
 *
 * @method \League\Flysystem\FilesystemInterface addPlugin(\League\Flysystem\PluginInterface $plugin)
 * @method void assertAbsent(string $path)
 * @method void assertPresent(string $path)
 * @method boolean copy(string $path, string $newpath)
 * @method boolean createDir(string $dirname, array $config = null)
 * @method boolean delete(string $path)
 * @method boolean deleteDir(string $dirname)
 * @method \League\Flysystem\Handler get(string $path, \League\Flysystem\Handler $handler = null)
 * @method \League\Flysystem\AdapterInterface getAdapter()
 * @method \League\Flysystem\Config getConfig()
 * @method array|false getMetadata(string $path)
 * @method string|false getMimetype(string $path)
 * @method integer|false getSize(string $path)
 * @method integer|false getTimestamp(string $path)
 * @method string|false getVisibility(string $path)
 * @method array getWithMetadata(string $path, array $metadata)
 * @method boolean has(string $path)
 * @method array listContents(string $directory = '', boolean $recursive = false)
 * @method array listFiles(string $path = '', boolean $recursive = false)
 * @method array listPaths(string $path = '', boolean $recursive = false)
 * @method array listWith(array $keys = [], $directory = '', $recursive = false)
 * @method boolean put(string $path, string $contents, array $config = [])
 * @method boolean putStream(string $path, resource $resource, array $config = [])
 * @method string|false read(string $path)
 * @method string|false readAndDelete(string $path)
 * @method resource|false readStream(string $path)
 * @method boolean rename(string $path, string $newpath)
 * @method boolean setVisibility(string $path, string $visibility)
 * @method boolean update(string $path, string $contents, array $config = [])
 * @method boolean updateStream(string $path, resource $resource, array $config = [])
 * @method boolean write(string $path, string $contents, array $config = [])
 * @method boolean writeStream(string $path, resource $resource, array $config = [])
 *
 */
class FileManagerApi
{
    /**
     * @var null
     */
    private $_filesystem = null;

    /**
     * @var null
     */
    private $_translate;

    /**
     * @param Filesystem $fs
     * @param bool|true $muteErrors
     */
    public function __construct(Filesystem $fs, $muteErrors = false)
    {
        if (!$muteErrors) {
            ini_set('display_errors', 1);
        }

        $this->_filesystem = $fs;
        $this->_translate  = new Translate(\Yii::$app->language);
    }

    /**
     * WORKS
     *
     * @param $query
     * @param $request
     * @param $files
     *
     * @return Response
     */
    public function postHandler($query, $request, $files)
    {
        // Probably file upload
        if (!isset($request['action'])
            && (isset($_SERVER["CONTENT_TYPE"])
                && strpos($_SERVER["CONTENT_TYPE"], 'multipart/form-data') !== false)
        ) {
            $uploaded = $this->uploadAction($request['destination'], $files);
            if ($uploaded === true) {
                $response = $this->simpleSuccessResponse();
            } else {
                $response = $this->simpleErrorResponse($this->_translate->upload_failed);
            }

            return $response;
        }

        switch ($request['action']) {
            case 'list':

                $list = $this->listAction($request['path']);

                if (!is_array($list)) {
                    $response = $this->simpleErrorResponse($this->_translate->listing_filed);
                } else {
                    $response = new Response();
                    $response->setData(
                        [
                            'result' => $list
                        ]
                    );
                }
                break;

            case 'rename':
                $renamed = $this->renameAction($request['item'], $request['newItemPath']);
                if ($renamed === true) {
                    $response = $this->simpleSuccessResponse();
                } elseif ($renamed === 'notfound') {
                    $response = $this->simpleErrorResponse($this->_translate->file_not_found);
                } else {
                    $response = $this->simpleErrorResponse($this->_translate->renaming_failed);
                }
                break;

            case 'move':
                $moved = $this->moveAction($request['items'], $request['newPath']);
                if ($moved === true) {
                    $response = $this->simpleSuccessResponse();
                } else {
                    $response = $this->simpleErrorResponse($this->_translate->moving_failed);
                }
                break;

            case 'copy':
                $copied = $this->copyAction($request['items'], $request['newPath']);
                if ($copied === true) {
                    $response = $this->simpleSuccessResponse();
                } else {
                    $response = $this->simpleErrorResponse($this->_translate->copying_failed);
                }
                break;

            case 'remove':
                $removed = $this->removeAction($request['items']);
                if ($removed === true) {
                    $response = $this->simpleSuccessResponse();
                } elseif ($removed === 'notempty') {
                    $response = $this->simpleErrorResponse($this->_translate->removing_failed_directory_not_empty);
                } else {
                    $response = $this->simpleErrorResponse($this->_translate->removing_failed);
                }
                break;

            case 'edit':
                $edited = $this->editAction($request['item'], $request['content']);
                if ($edited !== false) {
                    $response = $this->simpleSuccessResponse();
                } else {
                    $response = $this->simpleErrorResponse($this->_translate->saving_failed);
                }
                break;

            case 'getContent':
                $content = $this->getContentAction($request['item']);
                if ($content !== false) {
                    $response = new Response();
                    $response->setData(
                        [
                            'result' => $content
                        ]
                    );
                } else {
                    $response = $this->simpleErrorResponse($this->_translate->file_not_found);
                }
                break;

            case 'createFolder':
                $created = $this->createFolderAction($request['newPath']);
                if ($created === true) {
                    $response = $this->simpleSuccessResponse();
                } elseif ($created === 'exists') {
                    $response = $this->simpleErrorResponse($this->_translate->folder_already_exists);
                } else {
                    $response = $this->simpleErrorResponse($this->_translate->folder_creation_failed);
                }
                break;

            case 'changePermissions':
                $changed = $this->changePermissionsAction($request['items'], $request['perms'], $request['recursive']);
                if ($changed === true) {
                    $response = $this->simpleSuccessResponse();
                } elseif ($changed === 'missing') {
                    $response = $this->simpleErrorResponse($this->_translate->file_not_found);
                } else {
                    $response = $this->simpleErrorResponse($this->_translate->permissions_change_failed);
                }
                break;

            case 'compress':
                $compressed = $this->compressAction(
                    $request['items'],
                    $request['destination'],
                    $request['compressedFilename']
                );
                if ($compressed === true) {
                    $response = $this->simpleSuccessResponse();
                } else {
                    $response = $this->simpleErrorResponse($this->_translate->compression_failed);
                }
                break;

            case 'extract':
                $extracted = $this->extractAction($request['destination'], $request['item'], $request['folderName']);
                if ($extracted === true) {
                    $response = $this->simpleSuccessResponse();
                } elseif ($extracted === 'unsupported') {
                    $response = $this->simpleErrorResponse($this->_translate->archive_opening_failed);
                } else {
                    $response = $this->simpleErrorResponse($this->_translate->extraction_failed);
                }
                break;

            default:
                $response = $this->simpleErrorResponse($this->_translate->function_not_implemented);
                break;
        }

        return $response;
    }

    /**
     * WORKS
     *
     * @param $queries
     *
     * @return Response
     */
    public function getHandler($queries)
    {

        switch ($queries['action']) {
            case 'download':
                $downloaded = $this->downloadAction($queries['path']);
                if ($downloaded === true) {
                    exit;
                } else {
                    $response = $this->simpleErrorResponse($this->_translate->file_not_found);
                }

                break;

            default:
                $response = $this->simpleErrorResponse($this->_translate->function_not_implemented);
                break;
        }

        return $response;
    }

    /**
     * WORKS
     *
     * @param $file
     *
     * @return bool
     */
    private function downloadAction($file)
    {
        if (!$this->_filesystem->get($file)->isFile()) {
            return false;
        }

        $quoted = sprintf('"%s"', addcslashes(basename($file), '"\\'));
        $size   = $this->_filesystem->getSize($file);

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . $quoted);
        header('Content-Transfer-Encoding: binary');
        header('Connection: Keep-Alive');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . $size);

        $stream = $this->_filesystem->readStream($file);
        echo stream_get_contents($stream);
        fclose($stream);
        return true;
    }

    /**
     * WORKS
     *
     * @param $path
     * @param $files
     *
     * @return bool
     */
    private function uploadAction($path, $files)
    {
        foreach ($files as $file) {
            $stream   = fopen($file['tmp_name'], 'r+');
            $uploaded = $this->_filesystem->writeStream(
                $path . '/' . $file['name'],
                $stream,
                ['visibility' => AdapterInterface::VISIBILITY_PUBLIC]
            );
            if ($uploaded === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * WORKS TODO permissions
     *
     * @param $path
     *
     * @return array
     */
    private function listAction($path)
    {
        $files = [];
        foreach ($this->_filesystem->listContents($path) AS $file) {

            // fix for filesystems where folders has no date
            if (array_key_exists('timestamp', $file)) {
                $date = new \DateTime('@' . $this->_filesystem->getTimestamp($file['path']));
            } else {
                $date = new \DateTime();
            }

            // fix for filesystems where folders has no size
            if (array_key_exists('size', $file)) {
                $size = $file['size'];
            } else {
                $size = 0;
            }

            $files[] = [
                'name' => $file['basename'],
                // 'rights' => $this->_filesystem->getMetadata($file['path']),
                'size' => $size,
                'date' => $date->format('Y-m-d H:i:s'),
                'type' => $file['type'],
            ];
        }
        return $files;
    }

    /**
     * WORKS
     *
     * @param $oldPath
     * @param $newPath
     *
     * @return string
     */
    private function renameAction($oldPath, $newPath)
    {
        if (!$this->_filesystem->get($oldPath)->isFile() && !$this->_filesystem->get($oldPath)->isDir()) {
            return 'notfound';
        }
        return $this->_filesystem->get($oldPath)->rename($newPath);
    }

    /**
     * WORKS
     *
     * @param $oldPaths
     * @param $newPath
     *
     * @return bool
     */
    private function moveAction($oldPaths, $newPath)
    {
        foreach ($oldPaths as $oldPath) {
            if (!$this->_filesystem->get($oldPath)->isFile() && !$this->_filesystem->get($oldPath)->isDir()) {
                return false;
            }
            $renamed = $this->_filesystem->get($oldPath)->rename($newPath . '/' . basename($oldPath));
            if ($renamed === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * WORKS TODO set new filename on copy doesn't works, BUG
     *
     * @param $oldPaths
     * @param $newPath
     *
     * @return bool
     */
    private function copyAction($oldPaths, $newPath)
    {
        $newPath = ltrim($newPath, '/');
        foreach ($oldPaths as $oldPath) {
            if (!$this->_filesystem->get($oldPath)->isFile()) {
                return false;
            }
            $copied = $this->_filesystem->get($oldPath)->copy($newPath . '/' . basename($oldPath));
            if ($copied === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * WORKS
     *
     * @param $paths
     *
     * @return bool|string
     */
    private function removeAction($paths)
    {
        foreach ($paths as $path) {

            if ($this->_filesystem->get($path)->isDir()) {

                $dirEmpty = (new \FilesystemIterator($this->_filesystem->getAdapter()->getPathPrefix() . $path))->valid();

                if ($dirEmpty) {
                    return 'notempty';
                }
                $removed = $this->_filesystem->get($path)->deleteDir($path);
            } else {
                $removed = $this->_filesystem->get($path)->delete($path);
            }

            if ($removed === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * WORKS
     *
     * @param $path
     * @param $content
     *
     * @return int
     */
    private function editAction($path, $content)
    {
        if (!$this->_filesystem->get($path)->isFile()) {
            return false;
        }

        return file_put_contents($this->_filesystem->getAdapter()->getPathPrefix() . $path, $content);
    }

    /**
     * WORKS
     *
     * @param $path
     *
     * @return bool|string
     */
    private function getContentAction($path)
    {
        if (!$this->_filesystem->get($path)->isFile()) {
            return false;
        }

        return file_get_contents($this->_filesystem->getAdapter()->getPathPrefix() . $path);
    }

    /**
     * WORKS
     *
     * @param $path
     *
     * @return bool|string
     */
    private function createFolderAction($path)
    {
        if ($this->_filesystem->has($path)) {
            return 'exists';
        }

        return $this->_filesystem->createDir($path, ['visibility' => AdapterInterface::VISIBILITY_PUBLIC]);
    }

    /*private function changePermissionsAction($paths, $permissions, $recursive)
    {
        foreach ($paths as $path) {
            if (!file_exists($this->_filesystem->path . $path)) {
                return 'missing';
            }

            if (is_dir($path) && $recursive === true) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $item) {
                    $changed = chmod($this->_filesystem->path . $item, octdec($permissions));

                    if ($changed === false) {
                        return false;
                    }
                }
            }

            return chmod($this->_filesystem->path . $path, octdec($permissions));
        }
    }*/

    /*private function compressAction($paths, $destination, $archiveName)
    {
        $archivePath = $this->_filesystem->path . $destination . $archiveName;

        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE) !== true) {
            return false;
        }

        foreach ($paths as $path) {
            $zip->addFile($this->_filesystem->path . $path, basename($path));
        }

        return $zip->close();
    }*/

    /*private function extractAction($destination, $archivePath, $folderName)
    {
        $archivePath = $this->_filesystem->path . $archivePath;
        $folderPath  = $this->_filesystem->path . rtrim($destination, '/') . '/' . $folderName;

        $zip = new ZipArchive;
        if ($zip->open($archivePath) === false) {
            return 'unsupported';
        }

        mkdir($folderPath);
        $zip->extractTo($folderPath);
        return $zip->close();
    }*/

    private function simpleSuccessResponse()
    {
        $response = new Response();
        $response->setData(
            [
                'result' => [
                    'success' => true
                ]
            ]
        );

        return $response;
    }

    private function simpleErrorResponse($message)
    {
        $response = new Response();
        $response
            ->setStatus(500, 'Internal Server Error')
            ->setData(
                [
                    'result' => [
                        'success' => false,
                        'error'   => $message
                    ]
                ]
            );

        return $response;
    }

    private function parsePerms($perms)
    {
        if (($perms & 0xC000) == 0xC000) {
            // Socket
            $info = 's';
        } elseif (($perms & 0xA000) == 0xA000) {
            // Symbolic Link
            $info = 'l';
        } elseif (($perms & 0x8000) == 0x8000) {
            // Regular
            $info = '-';
        } elseif (($perms & 0x6000) == 0x6000) {
            // Block special
            $info = 'b';
        } elseif (($perms & 0x4000) == 0x4000) {
            // Directory
            $info = 'd';
        } elseif (($perms & 0x2000) == 0x2000) {
            // Character special
            $info = 'c';
        } elseif (($perms & 0x1000) == 0x1000) {
            // FIFO pipe
            $info = 'p';
        } else {
            // Unknown
            $info = 'u';
        }

        // Owner
        $info .= (($perms & 0x0100) ? 'r' : '-');
        $info .= (($perms & 0x0080) ? 'w' : '-');
        $info .= (($perms & 0x0040) ?
            (($perms & 0x0800) ? 's' : 'x') :
            (($perms & 0x0800) ? 'S' : '-'));

        // Group
        $info .= (($perms & 0x0020) ? 'r' : '-');
        $info .= (($perms & 0x0010) ? 'w' : '-');
        $info .= (($perms & 0x0008) ?
            (($perms & 0x0400) ? 's' : 'x') :
            (($perms & 0x0400) ? 'S' : '-'));

        // World
        $info .= (($perms & 0x0004) ? 'r' : '-');
        $info .= (($perms & 0x0002) ? 'w' : '-');
        $info .= (($perms & 0x0001) ?
            (($perms & 0x0200) ? 't' : 'x') :
            (($perms & 0x0200) ? 'T' : '-'));

        return $info;
    }
}