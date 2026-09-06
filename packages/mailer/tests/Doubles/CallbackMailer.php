<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests\Doubles;

use Closure;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A delegate whose `send()` runs whatever the test hands it — the way a
 * synchronous listener, a decorator or a transport calling back runs
 * inside a send. What that closure does with the mailer wrapping this
 * delegate is the behaviour under test.
 */
final class CallbackMailer implements MailerInterface
{
    public int $calls = 0;

    /**
     * @param Closure(int): void $inside given the number of this call, 1 for the first
     */
    public function __construct(private readonly Closure $inside) {}

    #[\Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        ($this->inside)(++$this->calls);
    }
}
