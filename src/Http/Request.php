<?php

declare(strict_types=1);

namespace Ldb\Http;

/**
 * Normalised view of the incoming request. Parameters are merged from the query string,
 * form fields and a JSON body (JSON wins), so controllers read everything via param().
 */
final class Request
{
    private string $method;
    /** @var array<string,mixed> */
    private array $query;
    /** @var array<string,mixed> */
    private array $params;
    /** @var array<string,string> lower-cased header names */
    private array $headers;
    /** @var array<string,mixed> */
    private array $files;
    /** @var array<string,mixed> */
    private array $server;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $params
     * @param array<string,string> $headers
     * @param array<string,mixed> $files
     * @param array<string,mixed> $server
     */
    public function __construct(
        string $method,
        array $query = [],
        array $params = [],
        array $headers = [],
        array $files = [],
        array $server = []
    ) {
        $this->method = strtoupper($method);
        $this->query = $query;
        $this->params = $params;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->files = $files;
        $this->server = $server;
    }

    public static function fromGlobals(): self
    {
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strncmp($key, 'HTTP_', 5) === 0) {
                $headers[str_replace('_', '-', strtolower(substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $params = $_GET;
        if ($method === 'POST') {
            $params = array_replace($params, $_POST);
            $contentType = $headers['content-type'] ?? '';
            if (stripos($contentType, 'application/json') !== false) {
                $raw = (string) file_get_contents('php://input');
                if ($raw !== '') {
                    $json = json_decode($raw, true);
                    if (!is_array($json)) {
                        throw ApiException::badRequest('Invalid JSON body');
                    }
                    $params = array_replace($params, $json);
                }
            }
        }

        return new self($method, $_GET, $params, $headers, $_FILES, $_SERVER);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function action(): string
    {
        $a = $this->query['action'] ?? $this->params['action'] ?? '';
        return is_string($a) ? $a : '';
    }

    /** @return mixed */
    public function param(string $name, $default = null)
    {
        return array_key_exists($name, $this->params) ? $this->params[$name] : $default;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->params) && $this->params[$name] !== null;
    }

    /** Required non-empty string parameter. */
    public function string(string $name): string
    {
        $v = $this->param($name);
        if (!is_scalar($v) || (string) $v === '') {
            throw ApiException::badRequest("Missing parameter: $name");
        }
        return (string) $v;
    }

    /** Optional string parameter; empty strings become null. */
    public function optString(string $name): ?string
    {
        $v = $this->param($name);
        if ($v === null || !is_scalar($v) || (string) $v === '') {
            return null;
        }
        return (string) $v;
    }

    public function int(string $name, int $default = 0): int
    {
        $v = $this->param($name);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $name, bool $default = false): bool
    {
        $v = $this->param($name);
        if ($v === null) {
            return $default;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) $v;
    }

    /**
     * Array parameter. Accepts JSON-encoded strings too (form posts).
     * @return array<mixed>
     */
    public function arr(string $name, bool $required = false): array
    {
        $v = $this->param($name);
        if (is_string($v) && $v !== '') {
            $decoded = json_decode($v, true);
            if (is_array($decoded)) {
                $v = $decoded;
            }
        }
        if (!is_array($v)) {
            if ($required) {
                throw ApiException::badRequest("Missing or invalid parameter: $name");
            }
            return [];
        }
        return $v;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->params;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string,mixed>|null single uploaded file entry */
    public function file(string $name): ?array
    {
        $f = $this->files[$name] ?? null;
        return is_array($f) ? $f : null;
    }

    public function clientIp(): string
    {
        // Proxy headers are deliberately ignored: they are client-controlled.
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isHttps(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        return ($https !== '' && strtolower((string) $https) !== 'off')
            || (string) ($this->server['SERVER_PORT'] ?? '') === '443';
    }
}
