<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A transport of the wrong class for the scheme that asked for it, built
 * around a value that must not survive the refusal — the trace of a
 * family rejection holds the object it rejected.
 */
final class SentinelTransport implements TransportInterface
{
    public function __construct(public readonly string $sentinel) {}

    #[\Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        return null;
    }

    #[\Override]
    public function __toString(): string
    {
        return 'sentinel://' . $this->sentinel;
    }
}
