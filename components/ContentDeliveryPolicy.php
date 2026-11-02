<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2026 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\components;

use League\Flysystem\Util\MimeType;
use yii\base\BaseObject;
use yii\web\Response;

/**
 * Security headers for files delivered by the stream and download actions.
 *
 * Files are delivered from the same origin as the application. A browser that navigates
 * to an SVG, HTML or XML file would render it as a document and run embedded scripts in
 * the context of the application. For these "active" types the policy forces
 * `Content-Disposition: attachment` and sends a sandbox `Content-Security-Policy`, so
 * scripts can not run even if the browser renders the file. Embedding such files with
 * `<img>` keeps working, because browsers ignore both headers for images.
 *
 * `X-Content-Type-Options: nosniff` is sent for every delivered file, so a browser does not
 * guess an active type from the content of e.g. a `.txt` file.
 *
 * Configure via `Module::$deliveryPolicy`:
 *
 * ```php
 * 'deliveryPolicy' => [
 *     'activeContentMimeTypes' => ['image/svg+xml', 'text/html', ...],
 * ],
 * ```
 */
class ContentDeliveryPolicy extends BaseObject
{
    /**
     * @var bool set to false to deliver files without the additional headers
     */
    public $enabled = true;

    /**
     * MIME types that are delivered as attachment with a sandbox CSP.
     * Shell wildcards are supported (see `fnmatch()`). The type is checked for the MIME type
     * reported by the filesystem and for the type derived from the file extension.
     *
     * @var string[]
     */
    public $activeContentMimeTypes = [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'text/xml',
        'application/xml',
        '*+xml',
        'text/xsl',
        'text/javascript',
        'text/ecmascript',
        'application/javascript',
        'application/x-javascript',
        'application/ecmascript',
        'application/x-shockwave-flash',
    ];

    /**
     * @var string|null CSP header value for active content, `null` disables the header
     */
    public $contentSecurityPolicy = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    /**
     * @var bool send `X-Content-Type-Options: nosniff` for every delivered file
     */
    public $noSniff = true;

    /**
     * @param string $path file path, used to derive the MIME type from the extension
     * @param string|null $mimeType MIME type reported by the filesystem
     * @return bool
     */
    public function isActiveContent($path, $mimeType = null)
    {
        $candidates = array_filter([
            $mimeType,
            MimeType::detectByFilename((string)$path),
        ]);

        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim(explode(';', $candidate)[0]));
            foreach ($this->activeContentMimeTypes as $pattern) {
                if (fnmatch(strtolower($pattern), $candidate)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Adds the security headers to the response.
     *
     * The headers are added in `Response::EVENT_BEFORE_SEND`, after handlers that were
     * registered in the application config, so an application wide CSP does not replace
     * the sandbox policy. Both policies are enforced by the browser.
     *
     * @param Response $response
     * @param string $path
     * @param string|null $mimeType
     * @return bool whether the file must be delivered as attachment
     */
    public function apply(Response $response, $path, $mimeType = null)
    {
        if (!$this->enabled) {
            return false;
        }

        $isActive = $this->isActiveContent($path, $mimeType);
        $csp = $isActive ? $this->contentSecurityPolicy : null;
        $noSniff = $this->noSniff;

        $response->on(Response::EVENT_BEFORE_SEND, static function ($event) use ($csp, $noSniff) {
            $headers = $event->sender->getHeaders();
            if ($noSniff) {
                $headers->set('X-Content-Type-Options', 'nosniff');
            }
            if ($csp !== null) {
                $headers->add('Content-Security-Policy', $csp);
            }
        });

        return $isActive;
    }
}
