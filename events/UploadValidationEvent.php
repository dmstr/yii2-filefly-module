<?php

namespace hrzg\filefly\events;

use yii\base\Event;

/**
 * Triggered by `ContentValidator` for every uploaded file after the built-in checks passed.
 *
 * Set `$isValid` to false to reject the file, e.g. after a virus scan:
 *
 * ```php
 * 'contentValidator' => [
 *     'on validateFile' => function (UploadValidationEvent $event) {
 *         if (!myVirusScan($event->tempName)) {
 *             $event->isValid = false;
 *         }
 *     },
 * ],
 * ```
 */
class UploadValidationEvent extends Event
{
    /**
     * @var string original name of the uploaded file
     */
    public $filename;

    /**
     * @var string path of the uploaded temporary file
     */
    public $tempName;

    /**
     * @var string MIME type detected from the file content
     */
    public $mimeType;

    /**
     * @var bool set to false to reject the file
     */
    public $isValid = true;

    /**
     * @var string|null message key returned to the client if the file is rejected,
     * see `messages/*.json`
     */
    public $errorMessage;
}
