<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2020 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\controllers;

use Exception;
use hrzg\filefly\components\FileManager;
use hrzg\filefly\events\FileEvent;
use hrzg\filefly\models\FileflyHashmap;
use hrzg\filefly\Module;
use hrzg\filefly\Module as Filefly;
use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use Yii;
use yii\filters\AccessControl;
use yii\filters\Cors;
use yii\filters\VerbFilter;
use yii\helpers\Inflector;
use yii\web\Controller as WebController;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * --- PUBLIC PROPERTIES ---
 *
 * @property Module $module
 */
class ApiController extends WebController
{

    public $enableCsrfValidation = false;

    private $_uploadedFiles = [];

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs'] = [
            'class' => VerbFilter::class,
            'actions' => [
                'download' => ['GET'],
                'stream' => ['GET'],
                'list' => ['POST'],
                'remove' => ['POST'],
                'move' => ['POST'],
                'copy' => ['POST'],
                'create-folder' => ['POST'],
                'rename' => ['POST'],
                'upload' => ['POST'],
                'resolve-permissions' => ['POST'],
                'change-permissions' => ['POST'],
                'search' => ['GET']
            ]
        ];
        $behaviors['corsFilter'] = [
            'class' => Cors::class,
            'cors' => [
                'Origin' => ['*'],
                'Access-Control-Request-Method' => [
                    'GET',
                    'POST',
                    'PUT',
                    'PATCH',
                    'DELETE',
                    'HEAD',
                    'OPTIONS'
                ],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => false,
                'Access-Control-Max-Age' => 86400,
            ],
        ];
        $behaviors['access'] = [
            'class' => AccessControl::class,
            'rules' => [
                [
                    'allow' => true,
                    'matchCallback' => function ($rule, $action) {
                        return Yii::$app->user->can(
                            $this->module->id . '_' . $this->id . '_' . $action->id,
                            ['route' => true]
                        );
                    },
                ]
            ]
        ];
        return $behaviors;
    }

    /**
     * @param string $id
     * @param array $params
     *
     * @return mixed
     * @throws \yii\base\InvalidRouteException
     * @throws NotFoundHttpException
     */
    public function runAction($id, $params = [])
    {
        if (Yii::$app->request->isPost) {
            $params = json_decode(Yii::$app->request->rawBody, true);

            if (isset($params['items']) && in_array($params['action'], ['remove', 'move', 'copy'], true)) {
                $params['items'] = json_encode($params['items']);
            }

            if (isset($params['item']) && in_array($params['action'], ['changePermissions'], true)) {
                $params['item'] = json_encode($params['item']);
            }

            if (!empty(Yii::$app->request->post('destination'))) {
                $params = [
                    'path' => Yii::$app->request->post('destination'),
                    'action' => 'upload'
                ];
                $this->_uploadedFiles = $_FILES;
            }
        }

        if (!Yii::$app->request->isGet) {
            if (!Yii::$app->user->can(Module::ACCESS_ROLE_DEFAULT)) {
                throw new HttpException(403, 'Action not allowed');
            }
        }

        if (!isset($params['action']) || !in_array($id, ['', 'index'], true)) {
            throw new NotFoundHttpException(Yii::t('filefly', 'Page not found.'));
        }

        $id = Inflector::camel2id($params['action']);
        return parent::runAction($id, $params);
    }

    /**
     * @param $newPath
     * @param $items
     *
     * @return Response
     */
    public function actionMove($newPath, $items)
    {
        $paths = json_decode($items, true);
        $fileSystem = FileManager::fileSystem();
        $success = false;
        $errorMessage = '';

        // ensure hashmap entry
        $fileSystem->check($newPath, $this->module->repair);

        if (!$fileSystem->grantAccess($newPath, FileManager::ACCESS_UPDATE)) {
            $errorMessage = 'nopermission';
        } else {

            foreach ($paths as $oldPath) {
                $this->trigger(FileEvent::EVENT_BEFORE_MOVE, new FileEvent(['filename' => $oldPath]));
                $fileSystem->check($oldPath, $this->module->repair);

                if (!$fileSystem->get($oldPath)->isFile() && !$fileSystem->get($oldPath)->isDir()) {
                    $errorMessage = 'notfound';
                    break;
                }

                $destPath = $newPath . '/' . basename($oldPath);

                $moved = $fileSystem->get($oldPath)->rename($destPath);
                if ($moved === false) {
                    $errorMessage = 'movefailed';
                    break;
                }
                $success = true;

                // Update permissions
                $fileSystem->setAccess($oldPath, $destPath);
                $this->trigger(FileEvent::EVENT_AFTER_MOVE, new FileEvent(['filename' => $destPath]));
            }

        }

        if ($success) {
            $this->trigger(FileEvent::EVENT_MOVE_SUCCESS, new FileEvent());
        } else {
            $this->trigger(FileEvent::EVENT_MOVE_ERROR, new FileEvent([
                'errorMessage' => $errorMessage
            ]));
        }

        return $this->asJson([
            'result' => [
                'success' => $success,
                'error' => FileManager::translate($errorMessage)
            ]
        ]);
    }

    /**
     * @param $items
     * @param $newPath
     * @param null $newFilename
     *
     * @return bool|Response
     */
    public function actionCopy($items, $newPath, $newFilename = null)
    {
        $paths = json_decode($items, true);
        $fileSystem = FileManager::fileSystem();
        $success = false;
        $errorMessage = '';
        $fileSystem->check($newPath, $this->module->repair);

        if (!$fileSystem->grantAccess($newPath, FileManager::ACCESS_UPDATE)) {
            $errorMessage = 'nopermission';
        } else {

            foreach ($paths as $oldPath) {
                $this->trigger(FileEvent::EVENT_BEFORE_COPY, new FileEvent(['filename' => $oldPath]));
                // ensure hashmap entry
                $fileSystem->check($oldPath, $this->module->repair);
                if (!$fileSystem->get($oldPath)->isFile()) {
                    return false;
                }

                // Build new path
                if ($newFilename === null) {
                    $filename = $newPath . '/' . basename($oldPath);
                } else {
                    $filename = $newPath . '/' . $newFilename;
                }

                // copy file
                $copied = $fileSystem->get($oldPath)->copy($filename);
                if ($copied === false) {
                    $errorMessage = 'copyfailed';
                } else {
                    $success = true;
                }

                // Set new permission
                $fileSystem->setAccess($filename);
                $this->trigger(FileEvent::EVENT_AFTER_COPY, new FileEvent(['filename' => $oldPath]));
            }

        }

        if ($success) {
            $this->trigger(FileEvent::EVENT_COPY_SUCCESS, new FileEvent());
        } else {
            $this->trigger(FileEvent::EVENT_COPY_ERROR, new FileEvent([
                'errorMessage' => $errorMessage
            ]));
        }

        return $this->asJson([
            'result' => [
                'success' => $success,
                'error' => FileManager::translate($errorMessage)
            ]
        ]);
    }

    /**
     * @param $newPath
     *
     * @return Response
     */
    public function actionCreateFolder($newPath)
    {
        $fileSystem = FileManager::fileSystem();
        $success = false;
        $errorMessage = '';
        if (!$fileSystem->grantAccess($newPath, FileManager::ACCESS_UPDATE)) {
            $errorMessage = 'nopermission';
        } else {
            $this->trigger(FileEvent::EVENT_BEFORE_CREATE_FOLDER, new FileEvent(['filename' => $newPath]));
            if ($fileSystem->has($newPath)) {
                $errorMessage = 'exists';
            } else {
                if ($fileSystem->createDir($newPath)) {
                    $success = true;
                }
            }
            $fileSystem->setAccess($newPath);
            $this->trigger(FileEvent::EVENT_AFTER_CREATE_FOLDER, new FileEvent(['filename' => $newPath]));
        }

        if ($success) {
            $this->trigger(FileEvent::EVENT_CREATE_FOLDER_SUCCESS, new FileEvent());
        } else {
            $this->trigger(FileEvent::EVENT_CREATE_FOLDER_ERROR, new FileEvent([
                'errorMessage' => $errorMessage
            ]));
        }

        return $this->asJson([
            'result' => [
                'success' => $success,
                'error' => FileManager::translate($errorMessage)
            ]
        ]);
    }

    /**
     * @param $item
     * @param $newItemPath
     *
     * @return Response
     */
    public function actionRename($item, $newItemPath)
    {
        $fileSystem = FileManager::fileSystem();
        $success = false;
        $errorMessage = '';
        $fileSystem->check($item, $this->module->repair);

        if (!$fileSystem->grantAccess($item, FileManager::ACCESS_UPDATE)) {
            $errorMessage = 'permission_edit_denied';
        } else {

            if (!$fileSystem->get($item)->isFile() && !$fileSystem->get($item)->isDir()) {
                $errorMessage = 'file_not_found';
            }
            $this->trigger(FileEvent::EVENT_BEFORE_RENAME, new FileEvent(['filename' => $item]));
            if ($fileSystem->get($item)->rename($newItemPath)) {
                $success = true;
                $fileSystem->setAccess($item, $newItemPath);
            } else {
                $errorMessage = 'renaming_failed';
            }
            $this->trigger(FileEvent::EVENT_AFTER_RENAME, new FileEvent(['filename' => $newItemPath]));
        }

        if ($success) {
            $this->trigger(FileEvent::EVENT_RENAME_SUCCESS, new FileEvent());
        } else {
            $this->trigger(FileEvent::EVENT_RENAME_ERROR, new FileEvent([
                'errorMessage' => $errorMessage
            ]));
        }

        return $this->asJson([
            'result' => [
                'success' => $success,
                'error' => FileManager::translate($errorMessage)
            ]
        ]);
    }

    /**
     * @param $path
     *
     * @return Response
     */
    public function actionUpload($path)
    {
        Yii::info('Starting upload action...', __METHOD__);

        $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $fileSystem = FileManager::fileSystem();
        $path = ltrim($path, DIRECTORY_SEPARATOR);
        $success = false;
        $errorMessage = '';

        Yii::debug("Checking '$path'", __METHOD__);
        $fileSystem->check($path, $this->module->repair);

        if ($fileSystem->grantAccess($path, FileManager::ACCESS_UPDATE)) {
            foreach ($this->_uploadedFiles as $file) {
                $this->trigger(FileEvent::EVENT_BEFORE_UPLOAD, new FileEvent(['filename' => $file['name'] ?? 'name']));
                $stream = fopen($file['tmp_name'], 'rb+');

                if ($this->module->slugNames) {
                    $pathInfo = pathinfo($file['name']);
                    // check if filename has extension
                    $fileName = trim(Inflector::slug($pathInfo['filename']), '/');
                    if (empty($pathInfo['extension'])) {
                        $fullPath = $path . $fileName;
                    } else {
                        $fullPath = $path . $fileName . '.' . strtolower($pathInfo['extension']);
                    }
                } else {
                    $fullPath = $path . trim($file['name'], '/');
                }

                $uploaded = false;
                try {
                    $mimeTypes = \Yii::$app->settings->get('mime-whitelist', 'filefly');
                    $acceptedMimeTypes = empty($mimeTypes) ? [] : explode(",", $mimeTypes);

                    if (in_array(mime_content_type($file['tmp_name']), $acceptedMimeTypes,
                            false) || empty($acceptedMimeTypes)) {
                        $uploaded = $fileSystem->writeStream(
                            $fullPath,
                            $stream,
                            [
                                'mimetype' => mime_content_type($file['tmp_name']),
                            ]);
                    } else {
                        Yii::error('MIME Type not allowed', __METHOD__);
                        $errorMessage = 'file_type_not_allowed';
                    }
                } catch (FileExistsException $e) {
                    Yii::error($e->getMessage(), __METHOD__);
                    $errorMessage = 'file_already_exists';
                    // continue with other uploads
                }
                if ($uploaded === false) {
                    Yii::error('Upload failed', __METHOD__);
                    $errorMessage = 'upload_failed';
                }

                $success = true;
                $fileSystem->setAccess($fullPath);
                $this->trigger(FileEvent::EVENT_AFTER_UPLOAD, new FileEvent(['filename' => $file['name'] ?? '']));
            }
        } else {
            $errorMessage = 'permission_upload_denied';
        }

        if ($success) {
            $this->trigger(FileEvent::EVENT_UPLOAD_SUCCESS, new FileEvent());
        } else {
            $this->trigger(FileEvent::EVENT_UPLOAD_ERROR, new FileEvent([
                'errorMessage' => $errorMessage
            ]));
        }

        return $this->asJson([
            'result' => [
                'success' => $success,
                'error' => FileManager::translate($errorMessage)
            ]
        ]);
    }

    /**
     * @param $path
     *
     * @return Response
     */
    public function actionList($path)
    {
        $files = [];
        $fileSystem = FileManager::fileSystem();

        $fileSystem->check($path, false);

        $parentFolderAccess = $fileSystem->grantAccess($path, FileManager::ACCESS_READ);
        if ($parentFolderAccess) {
            foreach ($fileSystem->listContents($path) as $item) {

                // ensure hashmap entry
                $fileSystem->check($item['path'], $this->module->repair);

                // check direct access
                $access = $fileSystem->grantAccess($item['path'], FileManager::ACCESS_READ, $this->module->repair);
                if ($access) {
                    if (array_key_exists('size', $item)) {
                        $size = array_key_exists('size', $item) ? $item['size'] : 0;
                    } else {
                        $size = 0;
                    }

                    if (array_key_exists('timestamp', $item) && !empty($item['timestamp'])) {
                        $time = $item['timestamp'];
                    } else {
                        $time = $fileSystem->getTimestamp($item['path']) ?: time();
                    }

                    $thumbnail = '';
                    if (is_callable($this->module->thumbnailCallback)) {
                        $thumbnail = call_user_func($this->module->thumbnailCallback, $item);
                    }

                    $itemUrls = [];
                    if (is_callable($this->module->urlCallback)) {
                        $itemUrls = call_user_func($this->module->urlCallback, $item);
                    }
                    $previewUrl = '';
                    if (is_callable($this->module->previewCallback)) {
                        $previewUrl = call_user_func($this->module->previewCallback, $item);
                    }

                    $files[] = [
                        'name' => $item['basename'],
                        'dirname' => $item['dirname'],
                        'path' => $item['path'],
                        'urls' => $itemUrls,
                        'thumbnail' => $thumbnail,
                        'preview' => $previewUrl,
                        'size' => $size,
                        'date' => date('Y-m-d H:i:s', $time),
                        'type' => $item['type'],
                        'extension' => $item['extension'] ?? ''
                    ];
                }
            }
        }

        return $this->asJson([
            'result' => $files
        ]);
    }

    /**
     * @param $items
     *
     * @return Response
     */
    public function actionRemove($items)
    {
        $paths = json_decode($items, true);
        $fileSystem = FileManager::fileSystem();
        $anyDeniedPerm = false;
        $success = false;
        $errorMessage = '';
        foreach ($paths as $path) {
            $this->trigger(FileEvent::EVENT_BEFORE_REMOVE, new FileEvent(['filename' => $path]));
            $fileSystem->check($path, $this->module->repair);

            if (!$fileSystem->grantAccess($path, FileManager::ACCESS_DELETE)) {
                $anyDeniedPerm = true;
                continue;
            }

            if ($fileSystem->get($path)->isDir()) {
                if (!$this->module->deleteRecursive && $fileSystem->isEmpty($path) === false) {
                    $errorMessage = 'permission_delete_denied';
                }

                $removed = $fileSystem->get($path)->deleteDir($path);
            } else {
                $removed = $fileSystem->get($path)->delete($path);
            }

            if ($removed === false) {
                $errorMessage = 'permission_delete_error';
                break;
            }

            // remove permission
            $removedPermission = $fileSystem->removeAccess($path, true);
            if ($removedPermission === false) {
                $errorMessage = 'permission_delete_denied';
                break;
            }
            $success = true;
            $this->trigger(FileEvent::EVENT_AFTER_REMOVE, new FileEvent(['filename' => $path]));
        }
        if ($anyDeniedPerm) {
            $errorMessage = 'permission_delete_denied';
        }

        if ($success) {
            $this->trigger(FileEvent::EVENT_REMOVE_SUCCESS, new FileEvent(['filename' => $paths]));
        } else {
            $this->trigger(FileEvent::EVENT_REMOVE_ERROR, new FileEvent([
                'errorMessage' => $errorMessage
            ]));
        }

        return $this->asJson([
            'result' => [
                'success' => $success,
                'error' => FileManager::translate($errorMessage)
            ]
        ]);
    }

    /**
     * @param $path
     *
     * @return bool
     * @throws \yii\base\ExitException
     * @throws NotFoundHttpException
     */
    public function actionDownload($path)
    {
        $fileSystem = FileManager::fileSystem();
        try {
            $element = $fileSystem->get($path);

            if (!$element->isFile()) {
                throw new NotFoundHttpException();
            }

            $mimeType = $fileSystem->getMimetype($path);
            $size = $fileSystem->getSize($path);

            $response = Yii::$app->response;
            $headers = $response->getHeaders();

            // set headers
            $headers->add('Content-Description', 'File Transfer');
            $headers->add('Content-Transfer-Encoding', 'binary');
            $headers->add('Connection', 'Keep-Alive');

            $stream = $fileSystem->readStream($path);
            $this->trigger(FileEvent::EVENT_BEFORE_DOWNLOAD, new FileEvent(['filename' => $path]));
            $response->sendStreamAsFile(
                $stream,
                basename($path),
                ['mimeType' => $mimeType, 'fileSize' => $size]
            );
        } catch (Exception $e) {
            throw new NotFoundHttpException();
        }
        $this->trigger(FileEvent::EVENT_AFTER_DOWNLOAD, new FileEvent(['filename' => $path]));
        Yii::$app->end();
    }

    /**
     * @param $path
     *
     * @return bool
     * @throws \yii\base\ExitException
     * @throws NotFoundHttpException
     */
    public function actionStream($path)
    {
        $fileSystem = FileManager::fileSystem();
        try {
            $element = $fileSystem->get($path);

            if (!$element->isFile()) {
                throw new NotFoundHttpException();
            }

            $mimeType = $fileSystem->getMimetype($path);
            $size = $fileSystem->getSize($path);

            $headers = Yii::$app->response->getHeaders();

            $headers->add('Content-Transfer-Encoding', 'binary');
            $headers->add('Connection', 'Keep-Alive');
            $offset = $this->module->streamExpireOffset ?: 604800; # 1 week
            if ($expiringDate = gmdate('D, d M Y H:i:s', time() + $offset)) {
                $headers->add('Expires', $expiringDate . ' GMT');
            }
            $headers->add('Cache-Control', 'public');

            $stream = $fileSystem->readStream($path);
            Yii::$app->response->sendStreamAsFile(
                $stream,
                basename($path),
                ['mimeType' => $mimeType, 'fileSize' => $size, 'inline' => 1]
            );
        } catch (Exception $e) {
            throw new NotFoundHttpException();
        }
        Yii::$app->end();
    }

    /**
     *
     * @param $path
     */
    public function actionResolvePermissions($path)
    {
        $fileSystem = FileManager::fileSystem();
        return $this->asJson([
            'auth' => $fileSystem->getPermissions($path)
        ]);
    }

    /**
     * @param null $path
     * @param null $item
     * @return Response
     */
    public function actionChangePermissions($path = null, $item = null)
    {
        $fileSystem = FileManager::fileSystem();
        $item = json_decode($item, true);

        // ensure hashmap entry
        $fileSystem->check($path, $this->module->repair);

        $this->trigger(FileEvent::EVENT_BEFORE_CHANGE_PERMISSION, new FileEvent(['filename' => $path]));
        $updateItemAuth = $fileSystem->updatePermission($item, $path);
        $this->trigger(FileEvent::EVENT_AFTER_CHANGE_PERMISSION, new FileEvent(['filename' => $path]));
        $errorMessage = "";
        if ($updateItemAuth === false) {
            $errorMessage = "error updating permissions";
        }

        if ($updateItemAuth) {
            $this->trigger(FileEvent::EVENT_CHANGE_PERMISSION_SUCCESS, new FileEvent(['filename' => $path]));
        } else {
            $this->trigger(FileEvent::EVENT_CHANGE_PERMISSION_ERROR, new FileEvent([
                'errorMessage' => $errorMessage
            ]));
        }

        return $this->asJson([
            'result' => [
                'success' => $updateItemAuth,
                'error' => FileManager::translate($errorMessage)
            ]
        ]);
    }

    public function actionSearch($q, $limit = '')
    {

        $query = FileflyHashmap::find()
            ->select(['path'])
            ->andWhere(['=', 'component', $this->module->filesystem])
            ->andWhere(['LIKE', 'path', $q])
            ->orderBy(['updated_at' => SORT_DESC])
            ->limit($limit ?: null)
            ->asArray();

        $fileSystem = FileManager::fileSystem();
        // filter results, only files
        $result = [];
        foreach ($query->all() as $item) {

            // check read permissions or is folder
            try {
                if (!$fileSystem->grantAccess($item['path'],
                        Filefly::ACCESS_READ) || $fileSystem->get($item['path'])->isDir()) {
                    continue;
                }
            } catch (FileNotFoundException $e) {
                \Yii::warning($e->getMessage(), __METHOD__);
                continue;
            }

            $item['id'] = $item['path'];
            $item['mime'] = '';
            $result[] = $item;
        }

        return $this->asJson($result);
    }
}
