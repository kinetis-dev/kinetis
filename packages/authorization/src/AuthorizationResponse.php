<?php

declare(strict_types=1);

namespace Kinetis\Authorization;

/**
 * A check handed to Gate — a Policy method reference or a plain closure
 * — returns a plain `bool` for the ordinary case, or one of these when
 * the caller should see a specific reason for a denial rather than a
 * generic fallback message. Gate normalizes a bare bool via fromBool(),
 * so both shapes end up here regardless.
 */
final readonly class AuthorizationResponse
{
    private function __construct(
        public bool $allowed,
        public ?string $message,
    ) {}

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function deny(string $message = 'This action is unauthorized.'): self
    {
        return new self(false, $message);
    }

    public static function fromBool(bool $allowed): self
    {
        return $allowed ? self::allow() : self::deny();
    }
}
