<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests\Doubles;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A transport a fixture registry entry names as the exact class its
 * factory must return. It is not final, so {@see ImpostorTransport} can
 * extend it: the pair is how the exact-class rule is told apart from an
 * `instanceof` check, which the subclass would pass.
 */
class OpenTransport implements TransportInterface
{
    #[\Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        return null;
    }

    #[\Override]
    public function __toString(): string
    {
        return 'open://default';
    }
}
