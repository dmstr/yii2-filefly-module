<?php
// file generated with AI assistance: Claude Code - [2026-11-11 12:30:00 UTC]

namespace hrzg\filefly\tests\support;

/**
 * HTTP-level result of one API call, free of Yii and Flysystem types,
 * so assertions on it stay valid across library migrations.
 */
final class ApiResponse
{
    /**
     * @var int
     */
    public $statusCode;

    /**
     * @var array<string, string[]> header name (lower case) => values
     */
    public $headers;

    /**
     * @var string raw body as it would have been sent (JSON text, file content or error page)
     */
    public $body;

    /**
     * @var \Throwable|null exception that escaped the action and was rendered by the error handler
     */
    public $exception;

    public function __construct(int $statusCode, array $headers, string $body, ?\Throwable $exception = null)
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->body = $body;
        $this->exception = $exception;
    }

    /**
     * Decoded JSON body (associative arrays)
     *
     * @return mixed
     * @throws \JsonException if the body is not valid JSON
     */
    public function json()
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * First value of a header, `null` if absent
     */
    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];
        return $values === [] ? null : (string)$values[0];
    }

    /**
     * Short description for assertion messages
     */
    public function describe(): string
    {
        $text = 'HTTP ' . $this->statusCode;
        if ($this->exception !== null) {
            $text .= ', exception ' . get_class($this->exception) . ': ' . $this->exception->getMessage()
                . ' at ' . $this->exception->getFile() . ':' . $this->exception->getLine();
        }
        return $text . ', body: ' . mb_substr($this->body, 0, 500);
    }
}
