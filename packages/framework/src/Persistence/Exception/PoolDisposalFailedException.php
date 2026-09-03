<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown from Pool::acquire() when a member being permanently discarded
 * cannot be disposed via the pool's own $onDiscard callback either.
 *
 * $healthCheckFailure, reachable via healthCheckFailure(), is set only
 * when the discard was triggered by the health check itself throwing —
 * as opposed to cleanly returning false — and is always the primary
 * failure here, never replaced by the disposal failure that follows it:
 * it is what actually caused the member to be discarded in the first
 * place. It doubles as getPrevious() whenever it is set, so ordinary
 * exception-chain tooling and loggers still see it without needing to
 * know this class's own accessors. disposalFailure() is always set —
 * the cleanup failure this exception exists to report — and is what
 * getPrevious() falls back to when there was no health-check failure to
 * report instead (the member was simply unhealthy, cleanly).
 */
final class PoolDisposalFailedException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly ?Throwable $healthCheckFailure,
        private readonly Throwable $disposalFailure,
    ) {
        parent::__construct($message, previous: $healthCheckFailure ?? $disposalFailure);
    }

    public static function afterHealthCheckThrew(Throwable $healthCheckFailure, Throwable $disposalFailure): self
    {
        return new self(
            'A pooled connection failed its health check and could not be disposed either: '
            . $disposalFailure->getMessage(),
            $healthCheckFailure,
            $disposalFailure,
        );
    }

    public static function whileDiscardingUnhealthyMember(Throwable $disposalFailure): self
    {
        return new self(
            'A pooled connection failed its health check and could not be disposed: '
            . $disposalFailure->getMessage(),
            null,
            $disposalFailure,
        );
    }

    public function healthCheckFailure(): ?Throwable
    {
        return $this->healthCheckFailure;
    }

    public function disposalFailure(): Throwable
    {
        return $this->disposalFailure;
    }
}
