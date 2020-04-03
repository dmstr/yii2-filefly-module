<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2020 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\controllers;

use hrzg\filefly\components\FileManager;
use hrzg\filefly\Module;
use League\Flysystem\FileExistsException;
use yii\filters\AccessControl;
use yii\filters\Cors;
use yii\filters\VerbFilter;
use yii\helpers\Inflector;
use yii\web\Controller as WebController;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use \Exception;
use Yii;

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
                'upload' => ['POST']
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
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidRouteException
     * @return mixed
     */
    public function runAction($id, $params = [])
    {
        if (Yii::$app->request->isPost) {
            $params = json_decode(Yii::$app->request->rawBody, true);

            if (isset($params['items']) && in_array($params['action'], ['remove', 'move', 'copy'], true)) {
                $params['items'] = json_encode($params['items']);
            }

            if (!empty(Yii::$app->request->post('destination'))) {
                $params = [
                    'path' => Yii::$app->request->post('destination'),
                    'action' => 'upload'
                ];
                $this->_uploadedFiles = $_FILES;
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

        if (!$fileSystem->grantAccess($newPath, Filefly::ACCESS_UPDATE)) {
            $errorMessage = 'nopermission';
        }

        foreach ($paths as $oldPath) {

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

            // Update permissions
            $fileSystem->setAccess($oldPath, $destPath);
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
        }

        foreach ($paths as $oldPath) {

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
            }

            // Set new permission
            $fileSystem->setAccess($filename);
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
        }

        if ($fileSystem->has($newPath)) {
            $errorMessage = 'exists';
        }

        if ($fileSystem->createDir($newPath)) {
            $success = true;
        }

        $fileSystem->setAccess($newPath);
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

        if (!$fileSystem->grantAccess($item, Filefly::ACCESS_UPDATE)) {
            $errorMessage = 'permission_edit_denied';
        }

        if (!$fileSystem->get($item)->isFile() && !$fileSystem->get($item)->isDir()) {
            $errorMessage = 'file_not_found';
        }

        if ($fileSystem->get($item)->rename($newItemPath)) {
            $success = true;
        } else {
            $errorMessage = 'renaming_failed';
        }

        $fileSystem->setAccess($item, $newItemPath);

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
        $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $fileSystem = FileManager::fileSystem();
        $path = ltrim($path, DIRECTORY_SEPARATOR);
        $success = false;
        $errorMessage = '';
        $fileSystem->check($path, $this->module->repair);
        if ($fileSystem->grantAccess($path, FileManager::ACCESS_UPDATE)) {
            foreach ($this->_uploadedFiles as $file) {
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

                try {
                    $uploaded = $fileSystem->writeStream(
                        $fullPath,
                        $stream,
                        [
                            'mimetype' => mime_content_type($file['tmp_name']),
                        ]);

                } catch (FileExistsException $e) {
                    Yii::error($e->getMessage(), __METHOD__);
                    $errorMessage = 'file_already_exists';
                    break;
                }
                if ($uploaded === false) {
                    Yii::error('Upload failed', __METHOD__);
                    $errorMessage = 'upload_failed';
                }

                $success = true;
                $fileSystem->setAccess($fullPath);
            }
        } else {
            $errorMessage = 'permission_upload_denied';
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
            foreach ($fileSystem->listContents($path) AS $item) {

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

                    $files[] = [
                        'name' => $item['basename'],
                        'size' => $size,
                        'date' => date('Y-m-d H:i:s', $time),
                        'type' => $item['type'],
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
        }
        if ($anyDeniedPerm) {
            $errorMessage = 'permission_delete_denied';
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
     * @throws NotFoundHttpException
     * @throws \yii\base\ExitException
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
            $response->sendStreamAsFile(
                $stream,
                basename($path),
                ['mimeType' => $mimeType, 'fileSize' => $size]
            );
        } catch (Exception $e) {
            throw new NotFoundHttpException();
        }
        Yii::$app->end();
    }

    /**
     * @param $path
     *
     * @throws NotFoundHttpException
     * @throws \yii\base\ExitException
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
}