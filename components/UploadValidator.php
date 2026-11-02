<?php
/**
 * @link http://www.herzogkommunikation.de/
 * @copyright Copyright (c) 2026 herzog kommunikation GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\components;

use yii\base\BaseObject;

/**
 * Validates names and sizes of files before they are written to the filesystem.
 *
 * All rules are permissive by default, so existing installations keep their behavior.
 * Only file names with control characters, null bytes or path separators are always rejected.
 *
 * The validate methods return `null` if the file is valid, otherwise a message key
 * that can be passed to FileManager::translate().
 */
class UploadValidator extends BaseObject
{
    /**
     * Extensions which can be executed by common web server setups or rendered as active content in the browser.
     * Can be used as a starting point for Module::$deniedExtensions.
     */
    const DANGEROUS_EXTENSIONS = [
        // server side scripts
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx', 'shtml',
        // web server config
        'htaccess', 'htpasswd', 'user.ini',
        // executables
        'exe', 'com', 'bat', 'cmd', 'msi', 'dll', 'scr', 'vbs', 'ps1', 'jar',
        // active content in the browser
        'html', 'htm', 'xhtml', 'js', 'mjs', 'svg', 'svgz',
    ];

    /**
     * @var string[]|null allowed extensions (case-insensitive), checked against the last extension of the file name.
     * `null` allows all extensions, files without extension are rejected if a list is set
     */
    public $allowedExtensions;

    /**
     * @var string[] denied extensions (case-insensitive), checked against every extension of the file name,
     * so `shell.php.jpg` is rejected if `php` is listed
     */
    public $deniedExtensions = [];

    /**
     * @var bool reject file names with more than one extension, e.g. `demo.pdf.jpg` or `archive.tar.gz`
     */
    public $denyMultipleExtensions = false;

    /**
     * @var int|string|null max file size in bytes or as shorthand like `50M`, `null` means no limit
     */
    public $maxFileSize;

    /**
     * Validates an entry of `$_FILES` and the path it will be written to
     *
     * The original client file name and the target file name are both checked, so a name like `demo.pdf.jpg`
     * is rejected even if it would be changed by slugging before it is saved.
     *
     * @param array $file entry of `$_FILES`
     * @param string $targetPath path the file will be written to
     *
     * @return string|null
     */
    public function validateUpload(array $file, $targetPath)
    {
        if (!isset($file['name']) || !is_string($file['name'])) {
            return 'file_name_invalid';
        }

        $error = $this->validateFile($file);
        if ($error !== null) {
            return $error;
        }

        $error = $this->validateFileName($file['name']);
        if ($error !== null) {
            return $error;
        }

        $targetName = basename($targetPath);
        if ($targetName !== $file['name']) {
            return $this->validateFileName($targetName);
        }

        return null;
    }

    /**
     * Validates the upload status and size of an entry of `$_FILES`
     *
     * @param array $file
     *
     * @return string|null
     */
    public function validateFile(array $file)
    {
        $uploadError = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_OK;
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            return 'file_too_large';
        }
        if ($uploadError !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
            return 'upload_failed';
        }

        $maxFileSize = static::parseSize($this->maxFileSize);
        if ($maxFileSize !== null && filesize($file['tmp_name']) > $maxFileSize) {
            return 'file_too_large';
        }

        return null;
    }

    /**
     * Validates a file name (without path) against the configured extension rules
     *
     * @param string $fileName
     *
     * @return string|null
     */
    public function validateFileName($fileName)
    {
        if (!is_string($fileName) || trim($fileName, '. ') === '' || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $fileName)) {
            return 'file_name_invalid';
        }

        $extensions = static::extractExtensions($fileName);

        if ($this->denyMultipleExtensions && count($extensions) > 1) {
            return 'file_multiple_extensions_not_allowed';
        }

        $deniedExtensions = static::normalizeExtensions($this->deniedExtensions);
        foreach ($extensions as $index => $extension) {
            // also check combined extensions like "user.ini"
            $combined = implode('.', array_slice($extensions, $index));
            if (in_array($extension, $deniedExtensions, true) || in_array($combined, $deniedExtensions, true)) {
                return 'file_type_not_allowed';
            }
        }

        if ($this->allowedExtensions !== null) {
            $lastExtension = end($extensions);
            if ($lastExtension === false || !in_array($lastExtension, static::normalizeExtensions($this->allowedExtensions), true)) {
                return 'file_type_not_allowed';
            }
        }

        return null;
    }

    /**
     * Returns all extensions of a file name in lower case, e.g. `['pdf', 'jpg']` for `Demo.PDF.jpg`
     *
     * Trailing dots and spaces are ignored, because some filesystems strip them (`shell.php.` becomes `shell.php`).
     * For dot files like `.htaccess` the part after the leading dot is treated as extension.
     *
     * @param string $fileName
     *
     * @return string[]
     */
    public static function extractExtensions($fileName)
    {
        $parts = explode('.', rtrim($fileName, '. '));
        array_shift($parts);

        $extensions = [];
        foreach ($parts as $part) {
            $part = strtolower(trim($part));
            if ($part !== '') {
                $extensions[] = $part;
            }
        }
        return $extensions;
    }

    /**
     * Converts a size like `512K`, `50M` or `1G` to bytes
     *
     * @param int|string|null $size
     *
     * @return int|null
     */
    public static function parseSize($size)
    {
        if ($size === null || $size === '') {
            return null;
        }
        if (is_int($size)) {
            return $size;
        }
        if (!preg_match('/^\s*(\d+)\s*([KMG]?)B?\s*$/i', (string)$size, $matches)) {
            throw new \InvalidArgumentException("Invalid file size '$size'");
        }
        $bytes = (int)$matches[1];
        switch (strtoupper($matches[2])) {
            case 'G':
                $bytes *= 1024;
            // no break
            case 'M':
                $bytes *= 1024;
            // no break
            case 'K':
                $bytes *= 1024;
        }
        return $bytes;
    }

    /**
     * @param string[]|null $extensions
     *
     * @return string[]
     */
    protected static function normalizeExtensions($extensions)
    {
        $normalized = [];
        foreach ((array)$extensions as $extension) {
            $normalized[] = strtolower(trim($extension, '. '));
        }
        return $normalized;
    }
}
