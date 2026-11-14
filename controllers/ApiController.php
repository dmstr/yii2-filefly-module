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
use hrzg\filefly\components\ContentDeliveryPolicy;
use hrzg\filefly\components\ContentValidator;
use hrzg\filefly\components\FileManager;
use hrzg\filefly\components\Storage;
use hrzg\filefly\components\storage\InvalidPathException;
use hrzg\filefly\components\storage\ItemExistsException;
use hrzg\filefly\components\storage\ItemNotFoundException;
use hrzg\filefly\events\FileEvent;
use hrzg\filefly\models\FileflyHashmap;
use hrzg\filefly\Module;
use hrzg\filefly\Module as Filefly;
use Yii;
use yii\di\Instance;
use yii\filters\AccessControl;
use yii\filters\Cors;
use yii\filters\VerbFilter;
use yii\helpers\Inflector;
use yii\web\Controller as WebController;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * @extends WebController<Module>
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
                'search' => ['GET', 'POST']
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
        $storage = FileManager::storage();
        $accessManager = FileManager::access();
        $success = false;
        $errorMessage = '';

        // ensure hashmap entry
        $accessManager->check($newPath, $this->module->repair);

        if (!$accessManager->grantAccess($newPath, FileManager::ACCESS_UPDATE)) {
            $errorMessage = 'nopermission';
        } else {

            foreach ($paths as $oldPath) {
                $this->trigger(FileEvent::EVENT_BEFORE_MOVE, new FileEvent(['filename' => $oldPath]));
                $accessManager->check($oldPath, $this->module->repair);

                // moving changes the item like a rename does; the items moved before stay moved
                if (!$accessManager->grantAccess($oldPath, FileManager::ACCESS_UPDATE)) {
                    $errorMessage = 'nopermission';
                    $success = false;
                    break;
                }

                $itemType = $this->getItemType($storage, $oldPath);
                if ($itemType !== 'file' && $itemType !== 'dir') {
                    $errorMessage = 'notfound';
                    break;
                }

                $destPath = $newPath . '/' . basename($oldPath);

                // a target the hashmap already holds under a name the database considers equal is
                // refused like an existing one
                if ($accessManager->hasConflictingRow($destPath, $oldPath)) {
                    Yii::warning("Target '$destPath' collides with the hashmap row of another path", __METHOD__);
                    $errorMessage = 'file_already_exists';
                    $success = false;
                    break;
                }

                try {
                    $moved = $storage->move($oldPath, $destPath);
                } catch (ItemExistsException $e) {
                    // nothing is overwritten; the items moved before stay moved
                    Yii::warning($e->getMessage(), __METHOD__);
                    $errorMessage = 'file_already_exists';
                    $success = false;
                    break;
                }
                if ($moved === false) {
                    $errorMessage = 'movefailed';
                    break;
                }
                $success = true;

                // Update permissions
                $accessManager->setAccess($oldPath, $destPath);
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
     * @param string|null $newFilename
     *
     * @return bool|Response
     */
    public function actionCopy($items, $newPath, $newFilename = null)
    {
        $paths = json_decode($items, true);
        $storage = FileManager::storage();
        $accessManager = FileManager::access();
        $success = false;
        $errorMessage = '';
        $accessManager->check($newPath, $this->module->repair);

        if (!$accessManager->grantAccess($newPath, FileManager::ACCESS_UPDATE)) {
            $errorMessage = 'nopermission';
        } elseif ($newFilename !== null && ($validationError = $this->module->getUploadValidator()->validateFileName($newFilename)) !== null) {
            $errorMessage = $validationError;
        } else {

            foreach ($paths as $oldPath) {
                $this->trigger(FileEvent::EVENT_BEFORE_COPY, new FileEvent(['filename' => $oldPath]));
                // ensure hashmap entry
                $accessManager->check($oldPath, $this->module->repair);

                // the copy belongs to the copier, so reading the source must be allowed; the items
                // copied before stay copied
                if (!$accessManager->grantAccess($oldPath, FileManager::ACCESS_READ)) {
                    $errorMessage = 'nopermission';
                    $success = false;
                    break;
                }

                if ($this->getItemType($storage, $oldPath) !== 'file') {
                    return false;
                }

                // Build new path
                if ($newFilename === null) {
                    $filename = $newPath . '/' . basename($oldPath);
                } else {
                    $filename = $newPath . '/' . $newFilename;
                }

                // a target the hashmap already holds under a name the database considers equal is
                // refused like an existing one
                if ($accessManager->hasConflictingRow($filename)) {
                    Yii::warning("Target '$filename' collides with the hashmap row of another path", __METHOD__);
                    $errorMessage = 'file_already_exists';
                    $success = false;
                    break;
                }

                // copy file
                try {
                    $copied = $storage->copyFile($oldPath, $filename);
                } catch (ItemExistsException $e) {
                    // nothing is overwritten and the row of the existing target stays as it is; the
                    // items copied before stay copied
                    Yii::warning($e->getMessage(), __METHOD__);
                    $errorMessage = 'file_already_exists';
                    $success = false;
                    break;
                }
                if (!$copied) {
                    $errorMessage = 'copyfailed';
                } else {
                    $success = true;
                }

                // Set new permission
                $accessManager->setAccess($filename);
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
        $storage = FileManager::storage();
        $accessManager = FileManager::access();
        $success = false;
        $errorMessage = '';
        if (!$accessManager->grantAccess($newPath, FileManager::ACCESS_UPDATE)) {
            $errorMessage = 'nopermission';
        } else {
            $this->trigger(FileEvent::EVENT_BEFORE_CREATE_FOLDER, new FileEvent(['filename' => $newPath]));
            if ($accessManager->hasConflictingRow($newPath) || $storage->exists($newPath)) {
                // nothing was created, so no hashmap row is created or changed either; a name the
                // database considers equal to the path of another row counts as existing
                $errorMessage = 'exists';
            } else {
                if ($storage->createDirectory($newPath)) {
                    $success = true;
                }
                $accessManager->setAccess($newPath);
            }
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
        $storage = FileManager::storage();
        $accessManager = FileManager::access();
        $success = false;
        $errorMessage = '';
        $accessManager->check($item, $this->module->repair);

        if (!$accessManager->grantAccess($item, FileManager::ACCESS_UPDATE)) {
            $errorMessage = 'permission_edit_denied';
        } elseif (!$this->canRenameInto($accessManager, $item, $newItemPath)) {
            $errorMessage = 'permission_edit_denied';
        } elseif (($itemType = $this->getItemType($storage, $item)) === 'file' && ($validationError = $this->module->getUploadValidator()->validateFileName(basename($newItemPath))) !== null) {
            $errorMessage = $validationError;
        } else {

            if ($itemType !== 'file' && $itemType !== 'dir') {
                $errorMessage = 'file_not_found';
            }
            $this->trigger(FileEvent::EVENT_BEFORE_RENAME, new FileEvent(['filename' => $item]));
            if ($accessManager->hasConflictingRow($newItemPath, $item)) {
                // a name the database considers equal to the path of another row counts as existing
                Yii::warning("Target '$newItemPath' collides with the hashmap row of another path", __METHOD__);
                $errorMessage = 'file_already_exists';
                $moved = null;
            } else {
                try {
                    $moved = $storage->move($item, $newItemPath);
                } catch (ItemExistsException $e) {
                    // nothing is overwritten
                    Yii::warning($e->getMessage(), __METHOD__);
                    $errorMessage = 'file_already_exists';
                    $moved = null;
                }
            }
            if ($moved !== null) {
                if ($moved) {
                    $success = true;
                    $accessManager->setAccess($item, $newItemPath);
                } else {
                    $errorMessage = 'renaming_failed';
                }
                $this->trigger(FileEvent::EVENT_AFTER_RENAME, new FileEvent(['filename' => $newItemPath]));
            }
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

        $storage = FileManager::storage();
        $accessManager = FileManager::access();
        $path = ltrim($path, DIRECTORY_SEPARATOR);
        $success = false;
        $errorMessage = '';

        Yii::debug("Checking '$path'", __METHOD__);
        $accessManager->check($path, $this->module->repair);

        $uploadedFiles = [];
        if ($accessManager->grantAccess($path, FileManager::ACCESS_UPDATE)) {
            foreach ($this->_uploadedFiles as $file) {
                $this->trigger(FileEvent::EVENT_BEFORE_UPLOAD, new FileEvent(['filename' => $file['name'] ?? 'name']));
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

                $validationError = $this->module->getUploadValidator()->validateUpload($file, $fullPath);
                if ($validationError !== null) {
                    Yii::warning("Upload of '$fullPath' rejected: $validationError", __METHOD__);
                    $errorMessage = $validationError;
                    // continue with other uploads
                    continue;
                }

                $contentError = $this->getContentValidator()->validate($file['tmp_name'], $file['name']);
                if ($contentError !== null) {
                    Yii::warning("Upload of '{$file['name']}' rejected: $contentError", __METHOD__);
                    $errorMessage = $contentError;
                    // continue with other uploads
                    continue;
                }

                // a name the database considers equal to the path of another row counts as existing
                if ($accessManager->hasConflictingRow($fullPath)) {
                    Yii::warning("Upload of '$fullPath' collides with the hashmap row of another path", __METHOD__);
                    $errorMessage = 'file_already_exists';
                    // continue with other uploads
                    continue;
                }

                $stream = fopen($file['tmp_name'], 'rb+');
                $uploaded = false;
                try {
                    $mimeTypes = \Yii::$app->settings->get('mime-whitelist', 'filefly');
                    $acceptedMimeTypes = empty($mimeTypes) ? [] : explode(",", $mimeTypes);

                    if (in_array(mime_content_type($file['tmp_name']), $acceptedMimeTypes,
                            false) || empty($acceptedMimeTypes)) {
                        $uploaded = $storage->writeFile(
                            $fullPath,
                            $stream,
                            mime_content_type($file['tmp_name'])
                        );
                    } else {
                        Yii::error('MIME Type not allowed', __METHOD__);
                        $errorMessage = 'file_type_not_allowed';
                        // continue with other uploads
                        continue;
                    }
                } catch (ItemExistsException $e) {
                    Yii::error($e->getMessage(), __METHOD__);
                    $errorMessage = 'file_already_exists';
                    // continue with other uploads
                    continue;
                }
                if ($uploaded === false) {
                    Yii::error('Upload failed', __METHOD__);
                    $errorMessage = 'upload_failed';
                } else {
                    $uploadedFiles[] = $fullPath;
                    $success = true;
                    $accessManager->setAccess($fullPath);
                    $this->trigger(FileEvent::EVENT_AFTER_UPLOAD, new FileEvent(['filename' => $file['name'] ?? '']));
                }
            }
        } else {
            $errorMessage = 'permission_upload_denied';
        }

        if (!empty($errorMessage)) {
            $success = false;
        }

        if ($success) {
            $this->trigger(FileEvent::EVENT_UPLOAD_SUCCESS, new FileEvent());
        } else {
            $this->trigger(FileEvent::EVENT_UPLOAD_ERROR, new FileEvent([
                'errorMessage' => $errorMessage
            ]));
            $this->response->setStatusCode(400);
        }

        return $this->asJson([
            'result' => [
                'success' => $success,
                'error' => FileManager::translate($errorMessage),
                'uploadedFiles' => $uploadedFiles
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
        $storage = FileManager::storage();
        $accessManager = FileManager::access();

        $accessManager->check($path, false);

        $parentFolderAccess = $accessManager->grantAccess($path, FileManager::ACCESS_READ);
        if ($parentFolderAccess) {
            foreach ($storage->listItems($path) as $item) {

                // ensure hashmap entry
                $accessManager->check($item->getPath(), $this->module->repair);

                // check direct access
                $access = $accessManager->grantAccess($item->getPath(), FileManager::ACCESS_READ, $this->module->repair);
                if ($access) {
                    $size = $item->getFileSize() !== null ? $item->getFileSize() : 0;

                    if (!empty($item->getLastModified())) {
                        $time = $item->getLastModified();
                    } else {
                        $time = $storage->lastModified($item->getPath()) ?: time();
                    }

                    // the callbacks receive the item as array (documented in StorageItem::toArray())
                    $itemArray = $item->toArray();
                    $files[] = [
                        'name' => $item->getBasename(),
                        'dirname' => $item->getDirname(),
                        'path' => $item->getPath(),
                        'urls' => $this->getItemUrls($itemArray),
                        'thumbnail' => $this->getItemThumbnail($itemArray),
                        'preview' => $this->getItemPreview($itemArray),
                        'size' => $size,
                        'date' => date('Y-m-d H:i:s', $time),
                        'type' => $item->getType(),
                        'extension' => $item->getExtension() ?? $this->getItemExtensionFromPath($itemArray),
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
        $storage = FileManager::storage();
        $accessManager = FileManager::access();
        $anyDeniedPerm = false;
        $success = false;
        $errorMessage = '';
        foreach ($paths as $path) {
            $this->trigger(FileEvent::EVENT_BEFORE_REMOVE, new FileEvent(['filename' => $path]));
            $accessManager->check($path, $this->module->repair);

            if (!$accessManager->grantAccess($path, FileManager::ACCESS_DELETE)) {
                $anyDeniedPerm = true;
                continue;
            }

            $itemType = $this->getItemType($storage, $path);
            if ($itemType === 'dir') {
                if (!$this->module->deleteRecursive && $accessManager->isEmpty($path) === false) {
                    $errorMessage = 'permission_delete_denied';
                }

                $removed = $storage->deleteDirectory($path);
            } elseif ($itemType === 'file') {
                $removed = $storage->deleteFile($path);
            } else {
                // any other type got a directory handler from the Flysystem 1 handler API, which deleted it as directory
                $removed = $storage->deleteDirectory($path);
            }

            if ($removed === false) {
                $errorMessage = 'permission_delete_error';
                break;
            }

            // remove permission
            $removedPermission = $accessManager->removeAccess($path, true);
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
     * @return void
     * @throws \yii\base\ExitException
     * @throws NotFoundHttpException
     */
    public function actionDownload($path)
    {
        $storage = FileManager::storage();
        $filePath = $this->normalizeDeliveryPath($storage, $path);

        try {
            // one metadata request instead of separate existence, mime type and size requests
            $metadata = $storage->fileStat($filePath);

            if ($metadata === null || $metadata->getType() !== 'file') {
                throw new NotFoundHttpException(Yii::t('filefly', 'File not found at path: {path}', [
                    'path' => $path
                ]));
            }

            // many adapters (S3 among them) report no mime type with the metadata; instead of a
            // further request it is derived from the file extension
            $mimeType = $metadata->getMimeType() ?? (Storage::mimeTypeByFileName($filePath) ?: 'application/octet-stream');
            $size = $metadata->getFileSize() ?? 0;

            $this->getDeliveryPolicy()->apply(Yii::$app->response, $filePath, $mimeType);

            $response = Yii::$app->response;
            $headers = $response->getHeaders();

            $headers->add('Content-Description', 'File Transfer');
            $headers->add('Content-Transfer-Encoding', 'binary');
            $headers->add('Connection', 'Keep-Alive');

            // one read request, without a further existence check
            $stream = $storage->fileStream($filePath);
            if ($stream === null) {
                throw new NotFoundHttpException(Yii::t('filefly', 'File not found at path: {path}', [
                    'path' => $path
                ]));
            }

            $this->trigger(FileEvent::EVENT_BEFORE_DOWNLOAD, new FileEvent(['filename' => $path]));
            $response->sendStreamAsFile(
                $stream,
                basename($filePath),
                ['mimeType' => $mimeType, 'fileSize' => $size]
            );
        } catch (NotFoundHttpException $e) {
            throw $e;
        } catch (Exception $e) {
            // The adapter's message carries the absolute storage path; HttpException messages are
            // shown to the end user, so only the requested path goes into the response.
            Yii::warning($e->getMessage(), __METHOD__);
            throw new NotFoundHttpException(Yii::t('filefly', 'File not found at path: {path}', [
                'path' => $path
            ]));
        }
        $this->trigger(FileEvent::EVENT_AFTER_DOWNLOAD, new FileEvent(['filename' => $path]));
        Yii::$app->end();
    }

    /**
     * @param $path
     *
     * @return void
     * @throws \yii\base\ExitException
     * @throws NotFoundHttpException
     */
    public function actionStream($path)
    {
        $storage = FileManager::storage();
        $filePath = $this->normalizeDeliveryPath($storage, $path);

        try {
            // one metadata request instead of separate existence, mime type and size requests
            $metadata = $storage->fileStat($filePath);

            if ($metadata === null || $metadata->getType() !== 'file') {
                throw new NotFoundHttpException();
            }

            // many adapters (S3 among them) report no mime type with the metadata; instead of a
            // further request it is derived from the file extension
            $mimeType = $metadata->getMimeType() ?? (Storage::mimeTypeByFileName($filePath) ?: 'application/octet-stream');
            $size = $metadata->getFileSize() ?? 0;

            $forceAttachment = $this->getDeliveryPolicy()->apply(Yii::$app->response, $filePath, $mimeType);

            $headers = Yii::$app->response->getHeaders();
            $headers->add('Content-Transfer-Encoding', 'binary');
            $headers->add('Connection', 'Keep-Alive');
            $offset = $this->module->streamExpireOffset ?? 604800; // 1 week
            $headers->add('Expires', gmdate('D, d M Y H:i:s', time() + $offset) . ' GMT');
            $headers->add('Cache-Control', 'public');

            // one read request, without a further existence check
            $stream = $storage->fileStream($filePath);
            if ($stream === null) {
                throw new NotFoundHttpException();
            }

            Yii::$app->response->sendStreamAsFile(
                $stream,
                basename($filePath),
                ['mimeType' => $mimeType, 'fileSize' => $size, 'inline' => !$forceAttachment]
            );
        } catch (Exception $e) {
            throw new NotFoundHttpException();
        }
    }

    /**
     *
     * @param $path
     */
    public function actionResolvePermissions($path)
    {
        $accessManager = FileManager::access();

        // only the owner and admins may see the permissions; a denial answers like a missing row
        if (!$accessManager->canManagePermissions($path)) {
            return $this->asJson([
                'auth' => false
            ]);
        }

        return $this->asJson([
            'auth' => $accessManager->getPermissions($path)
        ]);
    }

    /**
     * @param string|null $path
     * @param string|null $item
     * @return Response
     */
    public function actionChangePermissions($path = null, $item = null)
    {
        $accessManager = FileManager::access();
        $item = json_decode($item, true);

        // only the owner and admins may change permissions; checked before the self-heal, so a denied
        // call creates no row
        if (!$accessManager->canManagePermissions($path)) {
            $errorMessage = 'permission_update_denied';
            $this->trigger(FileEvent::EVENT_CHANGE_PERMISSION_ERROR, new FileEvent([
                'errorMessage' => $errorMessage
            ]));
            return $this->asJson([
                'result' => [
                    'success' => false,
                    'error' => FileManager::translate($errorMessage)
                ]
            ]);
        }

        // ensure hashmap entry
        $accessManager->check($path, $this->module->repair);

        $this->trigger(FileEvent::EVENT_BEFORE_CHANGE_PERMISSION, new FileEvent(['filename' => $path]));
        $updateItemAuth = $accessManager->updatePermission($item, $path);
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

    /**
     * @param string $q search string
     * @param int|string|null $limit limit result
     * @param bool $only_files limit result to files, default true
     * @param int $q_limit limit items in the db query that will be checked for read access, default 1000
     * @param string|null $basePath optional basePath for the search, if set a `LIKE basePath%` condition will be added
     *
     * @return Response
     */
    public function actionSearch($q, $limit = null, $only_files = true, $q_limit = 1000, $basePath = null)
    {

        $basePath = (!empty($basePath) && is_string($basePath)) ? $basePath . '%' : null;

        // use default find() WITHOUT constraints from ActiveRecordAccessTrait here, as we MUST check permissions
        // via $accessManager->grantAccess() to get recursive read access checks
        FileflyHashmap::$activeAccessTrait = false;
        $query = FileflyHashmap::find()
            ->select(['path', 'type'])
            ->andWhere(['component' => $this->module->filesystem])
            ->andWhere(['LIKE', 'path', $q])
            ->andFilterWhere(['LIKE', 'path', $basePath, false])
            ->orderBy(['updated_at' => SORT_DESC])
            ->limit($q_limit ?: 1000)
            ->asArray();

        if ($only_files) {
            $query->andWhere(['type' => 'file']);
        }

        $accessManager = FileManager::access();
        $result = [];
        $found = 0;
        foreach ($query->all() as $item) {

            // check read permissions
            if (!$accessManager->grantAccess($item['path'], Filefly::ACCESS_READ)) {
                continue;
            }
            try {
                $item['extension'] = $this->getItemExtensionFromPath($item);
                $item['id'] = $item['path'];
                $item['thumbnail'] = $this->getItemThumbnail($item);
                $item['mime'] = '';
                $result[] = $item;
            } catch (ItemNotFoundException $e) {
                \Yii::warning($e->getMessage(), __METHOD__);
                continue;
            }
            $found++;
            // if result limit is reached, we're done
            if ($found === (int)$limit) {
                break;
            }

        }

        return $this->asJson($result);
    }


    /**
     * Whether `rename` may put the item into the folder of $newItemPath: always if that is the
     * item's current folder, otherwise the user needs update access on the destination folder
     * (checked like createFolder checks its parent: without self-heal, a folder without row inherits).
     *
     * @param \hrzg\filefly\components\AccessManager $accessManager
     * @param string $item
     * @param string $newItemPath
     *
     * @return bool
     */
    private function canRenameInto($accessManager, $item, $newItemPath)
    {
        $currentFolder = dirname('/' . trim((string)$item, '/'));
        $destinationFolder = dirname('/' . trim((string)$newItemPath, '/'));
        if ($currentFolder === $destinationFolder) {
            return true;
        }
        return (bool)$accessManager->grantAccess($destinationFolder, FileManager::ACCESS_UPDATE);
    }

    /**
     * Type of an existing item as the Flysystem 1 handler API reported it with
     * `$fileSystem->get($path)->getType()`: the type in its item info (`file`, `dir` or whatever
     * else the adapter reports), or `dir` if the storage returns no item info.
     *
     * @param Storage $storage
     * @param string $path
     *
     * @return string|null
     * @throws ItemNotFoundException if the path does not exist (also for the root)
     */
    private function getItemType(Storage $storage, $path)
    {
        $info = $storage->itemInfo($path);
        return $info !== null ? $info->getType() : 'dir';
    }

    private function getItemThumbnail(array $item)
    {
        $thumbnail = '';
        if (is_callable($this->module->thumbnailCallback)) {
            $thumbnail = call_user_func($this->module->thumbnailCallback, $item);
        }
        return $thumbnail;
    }

    private function getItemPreview(array $item)
    {
        $previewUrl = '';
        if (is_callable($this->module->previewCallback)) {
            $previewUrl = call_user_func($this->module->previewCallback, $item);
        }
        return $previewUrl;
    }

    private function getItemUrls(array $item)
    {
        $itemUrls = [];
        if (is_callable($this->module->urlCallback)) {
            $itemUrls = call_user_func($this->module->urlCallback, $item);
        }
        return $itemUrls;
    }

    /**
     * Normalises a requested path with Storage::normalizePath() (backslashes become `/`, empty and
     * `.` segments are dropped, `..` is resolved, control characters are rejected).
     *
     * stream and download use the normalised path for the file name, the mime type and the
     * delivery policy. A path that leaves the root or contains control characters is answered
     * like a missing file, without echoing the path.
     *
     * @param Storage $storage
     * @param string $path
     *
     * @return string path relative to the storage root, without leading or trailing slash
     * @throws NotFoundHttpException
     */
    private function normalizeDeliveryPath(Storage $storage, $path)
    {
        try {
            return $storage->normalizePath($path);
        } catch (InvalidPathException $e) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * @return ContentValidator
     * @throws \yii\base\InvalidConfigException
     */
    private function getContentValidator()
    {
        return Instance::ensure($this->module->contentValidator, ContentValidator::class);
    }

    /**
     * @return ContentDeliveryPolicy
     * @throws \yii\base\InvalidConfigException
     */
    private function getDeliveryPolicy()
    {
        return Instance::ensure($this->module->deliveryPolicy, ContentDeliveryPolicy::class);
    }

    private function getItemExtensionFromPath(array $item)
    {
        $extension = '';
        if (isset($item['path']) && $item['type'] === 'file') {
            $extension = pathinfo($item['path'], PATHINFO_EXTENSION);
        }
        return $extension;
    }

}