<?php

declare(strict_types=1);

namespace Ldb\Http;

/**
 * Exception that maps directly to the JSON error envelope:
 * {"ok":false,"error":{"message","code","sql"?}} with an HTTP status.
 */
class ApiException extends \RuntimeException
{
    private string $errorCode;
    private int $httpStatus;
    private ?string $sql;
    /** @var array<string,mixed> extra machine-readable error fields (e.g. failed change index) */
    private array $details = [];

    public function __construct(
        string $message,
        string $errorCode = 'bad_request',
        int $httpStatus = 400,
        ?string $sql = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->sql = $sql;
    }

    public static function badRequest(string $message): self
    {
        return new self($message, 'bad_request', 400);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self($message, 'not_found', 404);
    }

    public static function unsupported(string $message = 'This feature is not supported by the current driver'): self
    {
        return new self($message, 'unsupported', 400);
    }

    public static function authRequired(string $message = 'Authentication required'): self
    {
        return new self($message, 'auth_required', 401);
    }

    public static function csrf(string $message = 'Invalid or missing CSRF token'): self
    {
        return new self($message, 'csrf', 403);
    }

    public static function rateLimited(string $message = 'Too many attempts, please try again later'): self
    {
        return new self($message, 'rate_limited', 429);
    }

    public static function dbError(string $message, ?string $sql = null, ?\Throwable $previous = null): self
    {
        return new self($message, 'db_error', 400, $sql, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getSql(): ?string
    {
        return $this->sql;
    }

    /** Attach extra error fields, returned as error.details. @param array<string,mixed> $details */
    public function withDetails(array $details): self
    {
        $this->details = $details;
        return $this;
    }

    /** @return array<string,mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    /** @return array{message:string,code:string,sql?:string,details?:array} */
    public function toArray(): array
    {
        $err = ['message' => $this->getMessage(), 'code' => $this->errorCode];
        if ($this->sql !== null) {
            $err['sql'] = $this->sql;
        }
        if ($this->details) {
            $err['details'] = $this->details;
        }
        return $err;
    }
}
