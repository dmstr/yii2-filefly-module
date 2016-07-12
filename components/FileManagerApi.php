<?php
namespace hrzg\filefly\components;

use creocoder\flysystem\Filesystem;
use hrzg\filefly\models\FileflyHashmap;
use hrzg\filefly\Module;
use hrzg\filefly\plugins\GetPermissions;
use hrzg\filefly\plugins\GrantAccess;
use hrzg\filefly\plugins\RecursiveIterator;
use hrzg\filefly\plugins\RemoveAccess;
use hrzg\filefly\plugins\SetAccess;
use hrzg\filefly\plugins\UpdatePermission;
use League\Flysystem\FileExistsException;
use League\Flysystem\Util;
use yii\base\Component;

/**
 * Class FileManagerApi
 * @package hrzg\filefly\models
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class FileManagerApi extends Component
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
    public function __construct(Filesystem $fs, $fsComponent = null, $muteErrors = false)
    {
        parent::__construct();

        if (!$muteErrors) {
            ini_set('display_errors', 1);
        }

        // set filesystem
        $this->_filesystem = $fs;

        // add plugins
        $component = ['component' => $fsComponent];
        $this->_filesystem->addPlugin(new GrantAccess($component));
        $this->_filesystem->addPlugin(new SetAccess($component));
        $this->_filesystem->addPlugin(new RemoveAccess($component));
        $this->_filesystem->addPlugin(new GetPermissions($component));
        $this->_filesystem->addPlugin(new UpdatePermission($component));
        $this->_filesystem->addPlugin(new RecursiveIterator($component));

        // init language handler
        $this->_translate = new Translate(\Yii::$app->language);

        // disable find, beforeSave, beforeDelete for FileflyHashmap
        FileflyHashmap::$activeAccessTrait = false;

        // disable session flash messages in ActiveRecordAccessTrait
        FileflyHashmap::$enableFlashMessages = false;
    }

    /**
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
            switch (true) {
                case $uploaded === 'fileexists':
                    $response = $this->simpleErrorResponse($this->_translate->file_already_exists);
                    break;
                case $uploaded === 'uploadfailed':
                    $response = $this->simpleErrorResponse($this->_translate->upload_failed);
                    break;
                case $uploaded === 'nopermission':
                    $response = $this->simpleErrorResponse($this->_translate->permission_upload_denied);
                    break;
                case $uploaded === true:
                    $response = $this->simpleSuccessResponse();
                    break;
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
                switch (true) {
                    case $renamed === 'notfound':
                        $response = $this->simpleErrorResponse($this->_translate->file_not_found);
                        break;
                    case $renamed === 'nopermission':
                        $response = $this->simpleErrorResponse($this->_translate->permission_update_denied);
                        break;
                    case $renamed === 'renamefailed':
                        $response = $this->simpleErrorResponse($this->_translate->renaming_failed);
                        break;
                    case $renamed === true:
                        $response = $this->simpleSuccessResponse();
                        break;
                }
                break;

            case 'move':
                $moved = $this->moveAction($request['items'], $request['newPath']);
                switch (true) {
                    case $moved === 'notfound':
                        $response = $this->simpleErrorResponse($this->_translate->file_not_found);
                        break;
                    case $moved === 'nopermission':
                        $response = $this->simpleErrorResponse($this->_translate->permission_update_denied);
                        break;
                    case $moved === 'movefailed':
                        $response = $this->simpleErrorResponse($this->_translate->moving_failed);
                        break;
                    case $moved === true:
                        $response = $this->simpleSuccessResponse();
                        break;
                }
                break;

            case 'copy':
                // copy single file with new file name
                $newFilename = null;
                if (array_key_exists('singleFilename', $request)) {
                    $newFilename = $request['singleFilename'];
                }

                $copied = $this->copyAction($request['items'], $request['newPath'], $newFilename);
                switch (true) {
                    case $copied === 'copyfailed':
                        $response = $this->simpleErrorResponse($this->_translate->copying_failed);
                        break;
                    case $copied === true:
                        $response = $this->simpleSuccessResponse();
                        break;
                }
                break;

            case 'remove':
                $removed = $this->removeAction($request['items']);
                switch (true) {
                    case $removed === 'notempty':
                        $response = $this->simpleErrorResponse($this->_translate->removing_failed_directory_not_empty);
                        break;
                    case $removed === 'errorpermission':
                        $response = $this->simpleErrorResponse($this->_translate->permission_delete_error);
                        break;
                    case $removed === 'nopermission':
                        $response = $this->simpleErrorResponse($this->_translate->permission_delete_denied);
                        break;
                    case $removed === 'removefailed':
                        $response = $this->simpleErrorResponse($this->_translate->removing_failed);
                        break;
                    case $removed === true:
                        $response = $this->simpleSuccessResponse();
                        break;
                }
                break;

            case 'edit':
                $edited = $this->editAction($request['item'], $request['content']);
                switch (true) {
                    case $edited === 'nopermission':
                        $response = $this->simpleErrorResponse($this->_translate->permission_edit_denied);
                        break;
                    case $edited === true:
                        $response = $this->simpleSuccessResponse();
                        break;
                    default:
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
                switch (true) {
                    case $created === 'exists':
                        $response = $this->simpleErrorResponse($this->_translate->folder_already_exists);
                        break;
                    case $created === 'nopermission':
                        $response = $this->simpleErrorResponse($this->_translate->permission_folder_creation_failed);
                        break;
                    case $created === true:
                        $response = $this->simpleSuccessResponse();
                        break;
                    default:
                        $response = $this->simpleErrorResponse($this->_translate->folder_creation_failed);
                }

                break;

            case 'resolvePermissions':
                $response = new Response();
                $response->setData(
                    [
                        'auth' => $this->_filesystem->getPermissions($request['path'])
                    ]
                );

                break;

            case 'changePermissions':
                $changed = $this->changePermissionsAction($request['path'], $request['item']);
                if ($changed === true) {
                    $response = $this->simpleSuccessResponse();
                } else {
                    $response = $this->simpleErrorResponse($this->_translate->permissions_change_failed);
                }
                break;
            default:
                $response = $this->simpleErrorResponse($this->_translate->function_not_implemented);
                break;
        }

        return $response;
    }

    /**
     * @param $queries
     *
     * @return Response
     */
    public function getHandler($queries)
    {
        switch ($queries['action']) {
            case 'download':

                // check access first, and redirect to login if false
                if (!$this->_filesystem->grantAccess($queries['path'], Module::ACCESS_READ)) {
                    return $this->unauthorizedResponse($queries['action']);
                }

                // try to download file
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
     * @param $action
     *
     * @return Response
     */
    private function unauthorizedResponse($action)
    {
        $bodyHtml  = <<<Html
You are not allowed to <strong>$action</strong> this file!
Html;

        $response = new Response();
        $response->setStatus(401, 'Unauthorized');
        $response->setBody($bodyHtml);
        return $response;
    }

    /**
     * @return Response
     */
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

    /**
     * @param $message
     *
     * @return Response
     */
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

    /**
     * @param $path
     * @param $files
     *
     * @return bool
     */
    private function uploadAction($path, $files)
    {
        if ($this->_filesystem->grantAccess($path, Module::ACCESS_UPDATE)) {
            foreach ($files as $file) {
                $stream   = fopen($file['tmp_name'], 'r+');
                $fullPath = $path . '/' . $file['name'];

                try {
                    $uploaded = $this->_filesystem->writeStream($fullPath, $stream);
                } catch (FileExistsException $e) {
                    return 'fileexists';

                }
                if ($uploaded === false) {
                    return 'uploadfailed';
                }

                // set permission
                $this->_filesystem->setAccess($fullPath);
            }
            return true;
        }
        return 'nopermission';
    }

    /**
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
     * @param $path
     *
     * @return array
     */
    private function listAction($path)
    {
        $files = [];

        // get all filesystem path contents
        foreach ($this->_filesystem->listContents($path) AS $item) {
            if (!$this->_filesystem->grantAccess($item['path'], Module::ACCESS_READ)) {
                continue;
            }

            // fix for filesystems where folders has no date
            if (array_key_exists('timestamp', $item)) {
                $date = new \DateTime('@' . $this->_filesystem->getTimestamp($item['path']));
            } else {
                $date = new \DateTime();
            }

            // fix for filesystems where folders has no size
            if (array_key_exists('size', $item)) {
                $size = $item['size'];
            } else {
                $size = 0;
            }

            $files[] = [
                'name' => $item['basename'],
                // 'rights' => $this->_filesystem->getMetadata($item['path']),
                'size' => $size,
                'date' => $date->format('Y-m-d H:i:s'),
                'type' => $item['type'],
            ];
        }
        return $files;
    }

    /**
     * @param $oldPath
     * @param $newPath
     *
     * @return string
     */
    private function renameAction($oldPath, $newPath)
    {
        if (!$this->_filesystem->grantAccess($oldPath, Module::ACCESS_UPDATE)) {
            return 'nopermission';
        }

        if (!$this->_filesystem->get($oldPath)->isFile() && !$this->_filesystem->get($oldPath)->isDir()) {
            return 'notfound';
        }

        // rename
        $renamed = $this->_filesystem->get($oldPath)->rename($newPath);
        if ($renamed === false) {
            return 'renamefailed';
        }
        // Update permissions
        $this->_filesystem->setAccess($oldPath, $newPath);

        return true;
    }

    /**
     * @param $oldPaths
     * @param $newPath
     *
     * @return bool
     */
    private function moveAction($oldPaths, $newPath)
    {
        if (!$this->_filesystem->grantAccess($newPath, Module::ACCESS_UPDATE)) {
            return 'nopermission';
        }

        foreach ($oldPaths as $oldPath) {
            if (!$this->_filesystem->get($oldPath)->isFile() && !$this->_filesystem->get($oldPath)->isDir()) {
                return 'notfound';
            }

            // Build new path
            $destPath = $newPath . '/' . basename($oldPath);

            // Move file
            $moved = $this->_filesystem->get($oldPath)->rename($destPath);
            if ($moved === false) {
                return 'movefailed';
            }

            // Update permissions
            $this->_filesystem->setAccess($oldPath, $destPath);
        }
        return true;
    }

    /**
     * @param string $oldPaths
     * @param string $newPath
     * @param string $newFilename
     *
     * @return bool
     */
    private function copyAction($oldPaths, $newPath, $newFilename)
    {
        if (!$this->_filesystem->grantAccess($newPath, Module::ACCESS_UPDATE)) {
            return 'nopermission';
        }

        foreach ($oldPaths as $oldPath) {

            if (!$this->_filesystem->get($oldPath)->isFile()) {
                return false;
            }

            // Build new path
            if ($newFilename === null) {
                $filename = $newPath . '/' . basename($oldPath);
            } else {
                $filename = $newPath . '/' . $newFilename;
            }

            // copy file
            $copied = $this->_filesystem->get($oldPath)->copy($filename);
            if ($copied === false) {
                return 'copyfailed';
            }

            // Set new permission
            $this->_filesystem->setAccess($filename);
        }
        return true;
    }

    /**
     * @param $paths
     *
     * @return bool|string
     */
    private function removeAction($paths)
    {
        $anyDeniedPerm = false;
        foreach ($paths as $path) {

            if (!$this->_filesystem->grantAccess($path, Module::ACCESS_DELETE)) {
                $anyDeniedPerm = true;
                continue;
            }

            if ($this->_filesystem->get($path)->isDir()) {

                if ($this->_filesystem->isEmpty($path) === false) {
                    return 'notempty';
                }

                $removed = $this->_filesystem->get($path)->deleteDir($path);
            } else {
                $removed = $this->_filesystem->get($path)->delete($path);
            }

            if ($removed === false) {
                return 'removefailed';
            }

            // remove permission
            $removedPermission = $this->_filesystem->removeAccess($path);
            if ($removedPermission === false) {
                return 'errorpermission';
            }
        }
        if ($anyDeniedPerm) {
            return 'nopermission';
        }

        return true;
    }

    /**
     * TODO option globally tmp disabled in hrzg/yii2-filemanager-widgets
     * @param $path
     * @param $content
     *
     * @return int
     */
    private function editAction($path, $content)
    {
        if (!$this->_filesystem->grantAccess($path, Module::ACCESS_UPDATE)) {
            return 'nopermission';
        }

        if (!$this->_filesystem->get($path)->isFile()) {
            return false;
        }
        $fullPath = $this->_filesystem->getAdapter()->getPathPrefix() . $path;
        return file_put_contents($fullPath, $content);
    }

    /**
     * TODO option globally tmp disabled in hrzg/yii2-filemanager-widgets
     * @param $path
     *
     * @return bool|string
     */
    private function getContentAction($path)
    {
        if (!$this->_filesystem->get($path)->isFile()) {
            return false;
        }
        $fullPath = $this->_filesystem->getAdapter()->getPathPrefix() . $path;
        return file_get_contents($fullPath);
    }

    /**
     * @param $path
     *
     * @return bool|string
     */
    private function createFolderAction($path)
    {
        if (!$this->_filesystem->grantAccess($path, Module::ACCESS_UPDATE)) {
            return 'nopermission';
        }

        if ($this->_filesystem->has($path)) {
            return 'exists';
        }
        $newDir = $this->_filesystem->createDir($path);
        if ($newDir === false) {
            return false;
        }

        // set permissions
        $this->_filesystem->setAccess($path);
        return true;
    }

    /**
     * @param null $path
     * @param null $item
     *
     * @return bool
     */
    private function changePermissionsAction($path = null, $item = null)
    {
        $updateItemAuth = $this->_filesystem->updatePermission($item, $path);

        if ($updateItemAuth === false) {
            return false;
        }
        return true;
    }
}
