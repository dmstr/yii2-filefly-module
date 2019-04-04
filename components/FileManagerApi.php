<?php
/**
 * @link http://www.herzogkommunikation.de/
 * @copyright Copyright (c) 2017 herzog kommunikation GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace hrzg\filefly\components;

use creocoder\flysystem\Filesystem;
use hrzg\filefly\models\FileflyHashmap;
use hrzg\filefly\Module as Filefly;
use hrzg\filefly\plugins\GetPermissions;
use hrzg\filefly\plugins\GrantAccess;
use hrzg\filefly\plugins\RecursiveIterator;
use hrzg\filefly\plugins\RemoveAccess;
use hrzg\filefly\plugins\SelfHealKit;
use hrzg\filefly\plugins\SetAccess;
use hrzg\filefly\plugins\UpdatePermission;
use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use yii\base\Component;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;

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
     * the filefly module instance
     * @var null
     */
    private $_module = null;

    /**
     * @var null
     */
    private $_translate;

    /**
     * @param Filesystem $fs
     * @param bool|true $muteErrors
     */
    public function __construct(Filesystem $fs, $fsComponent = null, $muteErrors = false, Filefly $module)
    {
        parent::__construct();

        if (!$muteErrors) {
            ini_set('display_errors', 1);
        }

        // set module
        $this->_module = $module;

        // set filesystem
        $this->_filesystem = $fs;

        // add plugins
        $component = ['component' => $fsComponent];
        $this->_filesystem->addPlugin(new SelfHealKit($component));
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
        // default response
        $response = $this->simpleErrorResponse($this->_translate->function_not_implemented, 501);

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
                    $response = $this->simpleErrorResponse($this->_translate->permission_upload_denied,403);
                    break;
                case $uploaded === true:
                    $response = $this->simpleSuccessResponse();
                    break;
            }
            return $response;
        }

        switch ($request['action']) {

            case 'list':
                if (array_key_exists('recycle', $request) && $request['recycle'] === true) {
                    $recycle = $this->_filesystem->check(null, $this->_module->repair, false);
                    if ($recycle === false) {
                        return $this->simpleErrorResponse($this->_translate->recycling_failed);
                    }
                }

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
                        $response = $this->simpleErrorResponse($this->_translate->file_not_found, 404);
                        break;
                    case $renamed === 'nopermission':
                        $response = $this->simpleErrorResponse($this->_translate->permission_update_denied,403);
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
                        $response = $this->simpleErrorResponse($this->_translate->file_not_found, 404);
                        break;
                    case $moved === 'nopermission':
                        $response = $this->simpleErrorResponse($this->_translate->permission_update_denied,403);
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
                        $response = $this->simpleErrorResponse($this->_translate->permission_delete_denied,403);
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
                        $response = $this->simpleErrorResponse($this->_translate->permission_edit_denied,403);
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
                    $response = $this->simpleErrorResponse($this->_translate->file_not_found, 404);
                }
                break;

            case 'createFolder':
                $newPath = $request['newPath'];
                $pathInfo = pathinfo($request['newPath']);

                // ensure hashmap entry
                $this->_filesystem->check($pathInfo['dirname'], $this->_module->repair);

                // slug new folder name
                if ($this->_module->slugNames) {

                    $newPath = $pathInfo['dirname'].'/'.Inflector::slug($pathInfo['basename']);
                }
                $created = $this->createFolderAction($newPath);
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
                $response = $this->simpleErrorResponse($this->_translate->function_not_implemented, 501);
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

                if (!array_key_exists('path', $queries)) {
                    return $this->simpleErrorResponse($this->_translate->download_failed);
                }

                // check access first, and redirect to login if false
                if (!$this->_filesystem->grantAccess($queries['path'], Filefly::ACCESS_READ)) {
                    return $this->unauthorizedResponse($queries['action']);
                }

                // try to download file
                $downloaded = $this->downloadAction($queries['path']);
                if ($downloaded === true) {
                    exit;
                } else {
                    $response = $this->simpleErrorResponse($this->_translate->file_not_found, 404);
                }

                break;
            case 'stream':

                if (!array_key_exists('path', $queries)) {
                    return $this->simpleErrorResponse($this->_translate->streaming_failed);
                }

                // check access first, and redirect to login if false
                if (!$this->_filesystem->grantAccess($queries['path'], Filefly::ACCESS_READ)) {
                    return $this->unauthorizedResponse($queries['action']);
                }

                // try to stream file
                $streamed = $this->streamAction($queries['path']);
                if ($streamed === true) {
                    exit;
                }

                $response = $this->simpleErrorResponse($this->_translate->file_not_found ,404);

                break;
            case 'search':

                if (\Yii::$app->user->isGuest) {
                    return $this->unauthorizedResponse($queries['action']);
                }

                $path = strtolower(ArrayHelper::getValue($queries, 'q', '%'));
                $type = strtolower(ArrayHelper::getValue($queries, 'type', 'file'));
                $limit = ArrayHelper::getValue($queries, 'limit', 10);

                $response = (new Response())->setData($this->searchAction($path, $type, $limit));
                break;

            default:
                $response = $this->simpleErrorResponse($this->_translate->function_not_implemented, 501);
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
     * @param $statusCode
     *
     * @return Response
     */
    private function simpleErrorResponse($message,$statusCode = 500)
    {
        $response = new Response();
        $response
            ->setStatus($statusCode, $message)
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
     * @param string $path
     * @param string $type
     * @param integer $limit
     *
     * @return array
     */
    private function searchAction($path, $type, $limit)
    {
        $query = FileflyHashmap::find()
            ->select(['path'])
            ->andWhere(['=', 'component', $this->_module->filesystem])
            ->andWhere(['LIKE', 'path', $path])
            ->limit($limit)
            ->orderBy(['updated_at' => SORT_DESC])
            ->asArray();

        // filter results, only files
        $result = [];
        foreach ($query->all() as $item) {

            // check read permissions
            if ( ! $this->_filesystem->grantAccess($item['path'], Filefly::ACCESS_READ)) {
                continue;
            }

            try {
                $item['id'] = $item['path'];
                $item['mime'] = ''; // TODO: store and use mimetype in DB
                $result[] = $item;
            } catch (FileNotFoundException $e) {
                \Yii::warning($e->getMessage(), __METHOD__);
                continue;
            }
        }

        // return found elements
        return $result;
    }

    /**
     * @param $path
     * @param $files
     *
     * @return bool
     */
    private function uploadAction($path, $files)
    {
        // ensure hashmap entry
        $this->_filesystem->check($path, $this->_module->repair);
        if ($this->_filesystem->grantAccess($path, Filefly::ACCESS_UPDATE)) {
            foreach ($files as $file) {
                $stream   = fopen($file['tmp_name'], 'rb+');

                // parse $file['name'] for slugging if enabled
                if ($this->_module->slugNames) {
                    $pathInfo = pathinfo($file['name']);
                    // check if filename has extension
                    if(empty($pathInfo['extension'])) {
                        $fullPath = $path . '/' . Inflector::slug($pathInfo['filename']);
                    } else {
                        $fullPath = $path . '/' . Inflector::slug($pathInfo['filename']) . '.' . strtolower($pathInfo['extension']);
                    }
                } else {
                    $fullPath = $path.'/'.$file['name'];
                }

                try {
                    $uploaded = $this->_filesystem->writeStream(
                        $fullPath,
                        $stream,
                        [
                            'mimetype' => mime_content_type($file['tmp_name']),
                        ]);

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
     * @param string $path
     *
     * @return bool
     * @throws \yii\base\ExitException
     */
    private function downloadAction($path)
    {
        try {
            $element = $this->_filesystem->get($path);

            if (!$element->isFile()) {
                return false;
            }

            // get meta info
            $fileName = sprintf('"%s"', addcslashes(basename($path), '"\\'));
            $mimeType = $this->_filesystem->getMimetype($path);
            $size   = $this->_filesystem->getSize($path);

            // we must use \yii\web\HeaderCollection here, otherwise \yii\web\Response::sendStreamAsFile will override
            // with defaults from \yii\web\Response::setDownloadHeaders
            $headers = \Yii::$app->response->getHeaders();

            // set headers
            $headers->add('Content-Description', 'File Transfer');
            $headers->add('Content-Transfer-Encoding', 'binary');
            $headers->add('Connection', 'Keep-Alive');

            $stream = $this->_filesystem->readStream($path);
            \Yii::$app->response->sendStreamAsFile(
                $stream,
                basename($path),
                ['mimeType' => $mimeType, 'fileSize' => $size]
            );
        } catch (FileNotFoundException $e) {
            return false;
        }
        \Yii::$app->end();
    }

    /**
     * Stream a file
     * @param $path
     *
     * @return bool
     * @throws \yii\base\ExitException
     */
    private function streamAction($path)
    {
        try {
            $element = $this->_filesystem->get($path);

            if (!$element->isFile()) {
                return false;
            }

            $mimeType = $this->_filesystem->getMimetype($path);
            $size   = $this->_filesystem->getSize($path);

            // we must use \yii\web\HeaderCollection here, otherwise \yii\web\Response::sendStreamAsFile will override
            // with defaults from \yii\web\Response::setDownloadHeaders
            $headers = \Yii::$app->response->getHeaders();

            // set headers
            $headers->add('Content-Transfer-Encoding', 'binary');
            $headers->add('Connection', 'Keep-Alive');
            $offset = $this->_module->streamExpireOffset ? $this->_module->streamExpireOffset : 604800; # 1 week
            if ($expiringDate = gmdate("D, d M Y H:i:s", time() + $offset)) {
                $headers->add('Expires', $expiringDate . ' GMT');
            }
            $headers->add('Cache-Control', 'public');

            $stream = $this->_filesystem->readStream($path);
            \Yii::$app->response->sendStreamAsFile(
                $stream,
                basename($path),
                ['mimeType' => $mimeType, 'fileSize' => $size, 'inline' => 1]
            );
        } catch (FileNotFoundException $e) {
            return false;
        }
        \Yii::$app->end();
    }

    /**
     * @param $path
     *
     * @return array
     */
    private function listAction($path)
    {
        $files = [];

        // ensure hashmap entry
        $this->_filesystem->check($path, $this->_module->repair);

        // check only folder and folder parent access
        $parentFolderAccess = $this->_filesystem->grantAccess($path, Filefly::ACCESS_READ);

        // get all filesystem path contents
        foreach ($this->_filesystem->listContents($path) AS $item) {

            // ensure hashmap entry
            $this->_filesystem->check($item['path'], $this->_module->repair);

            // check direct access
            $access = $this->_filesystem->grantAccess($item['path'], Filefly::ACCESS_READ, false);

            // direct access denied
            if ($access === false) {
                continue;
            }

            // empty direct access and no parent access
            if ($access === null && !$parentFolderAccess) {
                continue;
            }

            // fix for filesystems where folders has no size
            if (array_key_exists('size', $item)) {
                $size = $item['size'];
            } else {
                $size = 0;
            }

            $files[] = [
                'name' => $item['basename'],
                'size' => $size,
                'date' => date('Y-m-d H:i:s',$this->_filesystem->getTimestamp($item['path']) ?: time()),
                'type' => $item['type'],
            ];
        }
        return $files;
    }

    /**
     * @param string $oldPath
     * @param string $newPath
     *
     * @return string
     */
    private function renameAction($oldPath, $newPath)
    {
        // ensure hashmap entry
        $this->_filesystem->check($oldPath, $this->_module->repair);

        if (!$this->_filesystem->grantAccess($oldPath, Filefly::ACCESS_UPDATE)) {
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
     * @param array $oldPaths
     * @param string $newPath
     *
     * @return bool
     */
    private function moveAction($oldPaths, $newPath)
    {
        // ensure hashmap entry
        $this->_filesystem->check($newPath, $this->_module->repair);

        if (!$this->_filesystem->grantAccess($newPath, Filefly::ACCESS_UPDATE)) {
            return 'nopermission';
        }

        foreach ($oldPaths as $oldPath) {

            // ensure hashmap entry
            $this->_filesystem->check($oldPath, $this->_module->repair);

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
     * @param array $oldPaths
     * @param string $newPath
     * @param string $newFilename
     *
     * @return bool
     */
    private function copyAction($oldPaths, $newPath, $newFilename)
    {
        // ensure hashmap entry
        $this->_filesystem->check($newPath, $this->_module->repair);

        if (!$this->_filesystem->grantAccess($newPath, Filefly::ACCESS_UPDATE)) {
            return 'nopermission';
        }

        foreach ($oldPaths as $oldPath) {

            // ensure hashmap entry
            $this->_filesystem->check($oldPath, $this->_module->repair);
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

            // ensure hashmap entry
            $this->_filesystem->check($path, $this->_module->repair);

            if (!$this->_filesystem->grantAccess($path, Filefly::ACCESS_DELETE)) {
                $anyDeniedPerm = true;
                continue;
            }

            if ($this->_filesystem->get($path)->isDir()) {
                if (!$this->_module->deleteRecursive && $this->_filesystem->isEmpty($path) === false) {
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
            $removedPermission = $this->_filesystem->removeAccess($path, true);
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
     * @param string $path
     * @param string $content
     *
     * @return int
     */
    private function editAction($path, $content)
    {
        // ensure hashmap entry
        $this->_filesystem->check($path, $this->_module->repair);

        if (!$this->_filesystem->grantAccess($path, Filefly::ACCESS_UPDATE)) {
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
     * @param string $path
     *
     * @return bool|string
     */
    private function getContentAction($path)
    {
        // ensure hashmap entry
        $this->_filesystem->check($path, $this->_module->repair);

        if (!$this->_filesystem->grantAccess($path, Filefly::ACCESS_UPDATE)) {
            return 'nopermission';
        }

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
        if (!$this->_filesystem->grantAccess($path, Filefly::ACCESS_UPDATE)) {
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
        // ensure hashmap entry
        $this->_filesystem->check($path, $this->_module->repair);

        $updateItemAuth = $this->_filesystem->updatePermission($item, $path);

        if ($updateItemAuth === false) {
            return false;
        }
        return true;
    }
}
