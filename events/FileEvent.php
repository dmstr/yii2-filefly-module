<?php

namespace hrzg\filefly\events;

use yii\base\Event;

/**
 * --- PROPERTIES ---
 *
 * @author Elias Luhr
 */
class FileEvent extends Event
{
    public const EVENT_BEFORE_UPLOAD = 'beforeUpload';
    public const EVENT_AFTER_UPLOAD = 'afterUpload';
    public const EVENT_UPLOAD_SUCCESS = 'uploadSuccess';
    public const EVENT_UPLOAD_ERROR = 'uploadError';
    public const EVENT_BEFORE_REMOVE = 'beforeRemove';
    public const EVENT_AFTER_REMOVE = 'afterRemove';
    public const EVENT_REMOVE_SUCCESS = 'removeSuccess';
    public const EVENT_REMOVE_ERROR = 'removeError';
    public const EVENT_BEFORE_DOWNLOAD = 'beforeDownload';
    public const EVENT_AFTER_DOWNLOAD = 'afterDownload';
    public const EVENT_BEFORE_CHANGE_PERMISSION = 'beforeChangePermission';
    public const EVENT_AFTER_CHANGE_PERMISSION = 'afterChangePermission';
    public const EVENT_CHANGE_PERMISSION_SUCCESS = 'changePermissionSuccess';
    public const EVENT_CHANGE_PERMISSION_ERROR = 'changePermissionError';
    public const EVENT_BEFORE_RENAME = 'beforeRename';
    public const EVENT_AFTER_RENAME = 'afterRename';
    public const EVENT_RENAME_SUCCESS = 'renameSuccess';
    public const EVENT_RENAME_ERROR = 'renameError';
    public const EVENT_BEFORE_CREATE_FOLDER = 'beforeCreateFolder';
    public const EVENT_AFTER_CREATE_FOLDER = 'afterCreateFolder';
    public const EVENT_CREATE_FOLDER_SUCCESS = 'createFolderSuccess';
    public const EVENT_CREATE_FOLDER_ERROR = 'createFolderError';
    public const EVENT_BEFORE_COPY = 'beforeCopy';
    public const EVENT_AFTER_COPY = 'afterCopy';
    public const EVENT_COPY_SUCCESS = 'copySuccess';
    public const EVENT_COPY_ERROR = 'copyError';
    public const EVENT_BEFORE_MOVE = 'beforeMove';
    public const EVENT_AFTER_MOVE = 'afterMove';
    public const EVENT_MOVE_SUCCESS = 'moveSuccess';
    public const EVENT_MOVE_ERROR = 'moveError';

    /**
     *  @var string|array filename: name of the uploaded file or created directory
     *
     * @var array
     */
    public $filename;

    /**
     * @var string
     */
    public $errorMessage;
}
