<?php

declare(strict_types=1);

namespace Ldb\Http;

/**
 * HTTP response: either a JSON document or a streamed download.
 */
final class Response
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    private int $status;
    /** @var array<string,string> */
    private array $headers;
    private ?string $body;
    /** @var callable|null function(resource $out): void */
    private $streamer;

    /** @param array<string,string> $headers */
    private function __construct(int $status, array $headers, ?string $body, ?callable $streamer = null)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->streamer = $streamer;
    }

    /** @param mixed $data */
    public static function ok($data): self
    {
        return self::json(['ok' => true, 'data' => $data]);
    }

    public static function error(ApiException $e, ?array $debug = null): self
    {
        $err = $e->toArray();
        if ($debug !== null) {
            $err['debug'] = $debug;
        }
        return self::json(['ok' => false, 'error' => $err], $e->getHttpStatus());
    }

    /** @param array<mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        $body = json_encode($payload, self::JSON_FLAGS);
        if ($body === false) {
            $status = 500;
            $body = '{"ok":false,"error":{"message":"Response encoding failed","code":"internal"}}';
        }
        return new self($status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ], $body);
    }

    /** Small server-rendered HTML page (never cached). */
    public static function html(string $body, int $status = 200): self
    {
        return new self($status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ], $body);
    }

    /** Redirect to a URL the caller has validated (relative, same application). */
    public static function redirect(string $location, int $status = 303): self
    {
        if (preg_match('/[\x00-\x20\x7F]/', $location)) {
            throw new \InvalidArgumentException('Invalid redirect location');
        }
        return new self($status, ['Location' => $location, 'Cache-Control' => 'no-store'], '');
    }

    /**
     * Streamed file download. The callback receives a writable stream (php://output).
     * @param callable $writer function(resource $out): void
     */
    public static function download(callable $writer, string $filename, string $mimeType): self
    {
        return new self(200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => self::contentDisposition($filename),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ], null, $writer);
    }

    /**
     * RFC 6266 / 5987 Content-Disposition with ASCII fallback and UTF-8 encoded name.
     */
    public static function contentDisposition(string $filename, string $type = 'attachment'): string
    {
        // Strip control chars, path separators and quotes for both variants.
        $clean = preg_replace('/[\x00-\x1F\x7F\/\\\\"]+/u', '_', $filename);
        if ($clean === null) {
            $clean = 'download';
        }
        $ascii = $clean;
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);
            if (is_string($t) && $t !== '') {
                $ascii = $t;
            }
        }
        $ascii = preg_replace('/[^\x20-\x7E]+/', '_', $ascii) ?? 'download';
        $ascii = str_replace(['"', '\\', '?'], '_', $ascii);
        if (trim($ascii, '_. ') === '') {
            $ascii = 'download';
        }
        return sprintf("%s; filename=\"%s\"; filename*=UTF-8''%s", $type, $ascii, rawurlencode($clean));
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if ($this->streamer !== null) {
            // Disable output buffering so large exports stream with constant memory.
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            try {
                ($this->streamer)($out);
            } catch (\Throwable $e) {
                error_log('[ldb] download failed: ' . get_class($e) . ': ' . $e->getMessage());
                if (headers_sent()) {
                    return; // already streaming: the file is truncated, nothing else can be done
                }
                // Nothing written yet (e.g. the first query failed): answer with a JSON error
                // instead of an empty attachment, so the export view can show the message.
                header_remove('Content-Disposition');
                header_remove('Content-Length');
                if ($e instanceof ApiException) {
                    $err = $e;
                } elseif ($e instanceof \PDOException || $e instanceof \RuntimeException) {
                    $err = ApiException::dbError($e->getMessage());
                } else {
                    $err = new ApiException('Download failed', 'internal', 500);
                }
                self::error($err)->send();
            } finally {
                fclose($out);
            }
            return;
        }
        echo $this->body ?? '';
    }
}
