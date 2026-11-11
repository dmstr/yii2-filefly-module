<?php
// file generated with AI assistance: Claude Code - [2026-11-11 12:30:00 UTC]

namespace hrzg\filefly\tests\support;

use yii\web\Response;

/**
 * Web response that runs the real `send()` pipeline (events, `prepare()`, formatters, stream ranges)
 * but captures headers and body instead of emitting them.
 *
 * Only the two output primitives are replaced: `sendHeaders()` keeps the headers in the collection,
 * `sendContent()` writes the body into `$capturedBody` instead of `echo`.
 */
class TestResponse extends Response
{
    /**
     * @var string|null body as it would have been sent to the client, `null` until `send()` ran
     */
    public $capturedBody;

    /**
     * @inheritdoc
     */
    protected function sendHeaders()
    {
        // headers stay in $this->headers, nothing is emitted
    }

    /**
     * Mirrors \yii\web\Response::sendContent() without output
     *
     * @inheritdoc
     */
    protected function sendContent()
    {
        if ($this->stream === null) {
            $this->capturedBody = (string)$this->content;
            return;
        }

        $body = '';
        if (is_callable($this->stream)) {
            foreach (call_user_func($this->stream) as $chunk) {
                $body .= $chunk;
            }
        } elseif (is_array($this->stream)) {
            [$handle, $begin, $end] = $this->stream;
            $meta = stream_get_meta_data($handle);
            if (!empty($meta['seekable'])) {
                fseek($handle, $begin);
            }
            $length = $end - $begin + 1;
            $body = $length > 0 ? (string)stream_get_contents($handle, $length) : '';
            fclose($handle);
        } else {
            $body = (string)stream_get_contents($this->stream);
            fclose($this->stream);
        }
        $this->capturedBody = $body;
    }
}
