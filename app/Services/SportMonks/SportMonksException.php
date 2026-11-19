<?php

namespace App\Services\SportMonks;

use RuntimeException;

/**
 * Raised when SportMonks cannot be reached or refuses a request. Messages never
 * contain the API token.
 */
class SportMonksException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const RATE_LIMITED = 'rate_limited';

    public const UNAUTHORIZED = 'unauthorized';

    public const NOT_FOUND = 'not_found';

    public const FAILED = 'failed';

    public function __construct(string $message, public readonly string $reason = self::FAILED, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('SPORTMONKS_API_TOKEN is not set in .env.', self::NOT_CONFIGURED);
    }

    public static function rateLimited(string $message): self
    {
        return new self($message, self::RATE_LIMITED, 429);
    }

    public static function unauthorized(int $status): self
    {
        return new self("SportMonks rejected the API token (HTTP {$status}). Check the token and that the plan includes cricket.", self::UNAUTHORIZED, $status);
    }

    public static function requestFailed(string $endpoint, int $status): self
    {
        return new self(
            "SportMonks answered HTTP {$status} for {$endpoint}.",
            $status === 404 ? self::NOT_FOUND : self::FAILED,
            $status,
        );
    }

    public static function connection(string $message): self
    {
        return new self("Could not reach SportMonks: {$message}");
    }

    /**
     * A fatal problem makes every following call pointless, so a sync stops instead of skipping the item.
     */
    public function isFatal(): bool
    {
        return in_array($this->reason, [self::NOT_CONFIGURED, self::RATE_LIMITED, self::UNAUTHORIZED], true);
    }
}
