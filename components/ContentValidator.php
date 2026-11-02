<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2026 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\components;

use finfo;
use hrzg\filefly\events\UploadValidationEvent;
use League\Flysystem\Util\MimeType;
use yii\base\Component;

/**
 * Validates the content of uploaded files before they are written to the filesystem.
 *
 * The MIME type is detected from the file content (fileinfo), not taken from the client.
 *
 * - `blockedMimeTypes` rejects files by their detected type, independent of the file name
 * - `checkMimeTypeMatchesExtension` rejects files whose content does not match the extension,
 *   e.g. a shell script named `image.jpg`
 * - `EVENT_VALIDATE_FILE` lets the application add own checks, e.g. a virus scanner
 *
 * Configure via `Module::$contentValidator`:
 *
 * ```php
 * 'contentValidator' => [
 *     'blockedMimeTypes' => \hrzg\filefly\components\ContentValidator::RECOMMENDED_BLOCKED_MIME_TYPES,
 *     'checkMimeTypeMatchesExtension' => true,
 * ],
 * ```
 */
class ContentValidator extends Component
{
    /**
     * @event UploadValidationEvent triggered for each file after the built-in checks passed
     */
    const EVENT_VALIDATE_FILE = 'validateFile';

    /**
     * Server side scripts, executables and documents that run scripts when opened in a browser
     */
    const RECOMMENDED_BLOCKED_MIME_TYPES = [
        'text/x-php',
        'application/x-php',
        'application/x-httpd-php',
        'text/x-shellscript',
        'text/x-perl',
        'text/x-python',
        'text/x-script.python',
        'text/x-ruby',
        'text/x-lua',
        'text/x-tcl',
        'text/x-msdos-batch',
        'application/x-executable',
        'application/x-pie-executable',
        'application/x-sharedlib',
        'application/x-mach-binary',
        'application/x-dosexec',
        'application/x-msdownload',
        'application/x-msi',
        'application/java-archive',
        'application/x-java-applet',
        'text/html',
        'application/xhtml+xml',
        'text/javascript',
        'application/javascript',
    ];

    /**
     * Accepted MIME types for extensions where fileinfo and the extension map of flysystem differ
     */
    const DEFAULT_MIME_TYPE_ALIASES = [
        'bmp' => ['image/x-ms-bmp'],
        'doc' => ['application/vnd.ms-office', 'application/CDFV2'],
        'xls' => ['application/vnd.ms-office', 'application/CDFV2'],
        'ppt' => ['application/vnd.ms-office', 'application/CDFV2'],
        'docx' => ['application/zip', 'application/octet-stream'],
        'xlsx' => ['application/zip', 'application/octet-stream'],
        'pptx' => ['application/zip', 'application/octet-stream'],
        'odt' => ['application/zip'],
        'ods' => ['application/zip'],
        'odp' => ['application/zip'],
        'svg' => ['image/svg'],
        'svgz' => ['application/gzip', 'application/x-gzip'],
        'gz' => ['application/x-gzip'],
        'tgz' => ['application/gzip', 'application/x-gzip'],
        'rar' => ['application/x-rar', 'application/x-rar-compressed'],
        'ico' => ['image/x-icon'],
        'ai' => ['application/pdf'],
        'mp3' => ['audio/mp3'],
        'm4a' => ['audio/mp4', 'audio/x-m4a'],
        'mp4' => ['audio/mp4'],
        'webm' => ['audio/webm'],
        'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg'],
        'wav' => ['audio/wav', 'audio/x-wav'],
    ];

    /**
     * @var bool set to false to skip all content checks
     */
    public $enabled = true;

    /**
     * MIME types (detected from the content) that are rejected. Shell wildcards are supported,
     * see `fnmatch()`. PHP files are blocked by default, see RECOMMENDED_BLOCKED_MIME_TYPES
     * for a stricter list.
     *
     * @var string[]
     */
    public $blockedMimeTypes = [
        'text/x-php',
        'application/x-php',
        'application/x-httpd-php',
    ];

    /**
     * Reject files whose detected MIME type does not match the MIME type of the file extension.
     * Files without extension or with an unknown extension are not checked.
     *
     * @var bool
     */
    public $checkMimeTypeMatchesExtension = false;

    /**
     * Additional accepted MIME types per extension, merged with DEFAULT_MIME_TYPE_ALIASES
     *
     * ```php
     * 'mimeTypeAliases' => ['dwg' => ['image/vnd.dwg', 'application/octet-stream']]
     * ```
     *
     * @var array
     */
    public $mimeTypeAliases = [];

    /**
     * @param string $tempName path of the uploaded temporary file
     * @param string $fileName original file name
     * @return string|null message key if the file is rejected, null if it is valid
     */
    public function validate($tempName, $fileName)
    {
        if (!$this->enabled) {
            return null;
        }

        $mimeType = $this->detectMimeType($tempName);

        if ($this->isBlocked($mimeType)) {
            return 'file_type_not_allowed';
        }

        if ($this->checkMimeTypeMatchesExtension && !$this->matchesExtension($mimeType, $fileName)) {
            return 'file_type_not_allowed';
        }

        $event = new UploadValidationEvent([
            'filename' => $fileName,
            'tempName' => $tempName,
            'mimeType' => $mimeType,
        ]);
        $this->trigger(self::EVENT_VALIDATE_FILE, $event);
        if (!$event->isValid) {
            return $event->errorMessage ?: 'file_content_rejected';
        }

        return null;
    }

    /**
     * @param string $file
     * @return string
     */
    public function detectMimeType($file)
    {
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file);
        return $mimeType ? strtolower($mimeType) : 'application/octet-stream';
    }

    /**
     * @param string $mimeType
     * @return bool
     */
    public function isBlocked($mimeType)
    {
        foreach ($this->blockedMimeTypes as $pattern) {
            if (fnmatch(strtolower($pattern), $mimeType)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $mimeType detected MIME type
     * @param string $fileName
     * @return bool
     */
    public function matchesExtension($mimeType, $fileName)
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $expected = MimeType::getExtensionToMimeTypeMap()[$extension] ?? null;

        // nothing to compare with
        if ($expected === null || $expected === 'application/octet-stream') {
            return true;
        }

        $accepted = [$expected, 'application/x-empty', 'inode/x-empty'];

        // fileinfo reports many text based formats as text/plain or generic xml/json
        if (strpos($expected, 'text/') === 0) {
            $accepted[] = 'text/plain';
        }
        if (preg_match('#[/+]xml$#', $expected)) {
            array_push($accepted, 'text/xml', 'application/xml', 'text/plain');
        }
        if (preg_match('#[/+]json$#', $expected)) {
            array_push($accepted, 'application/json', 'text/plain');
        }

        $aliases = array_merge(
            self::DEFAULT_MIME_TYPE_ALIASES[$extension] ?? [],
            $this->mimeTypeAliases[$extension] ?? []
        );
        foreach (array_merge($accepted, $aliases) as $pattern) {
            if (fnmatch(strtolower($pattern), $mimeType)) {
                return true;
            }
        }
        return false;
    }
}
