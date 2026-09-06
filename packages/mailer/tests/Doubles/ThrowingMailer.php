<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests\Doubles;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * A delegate that fails a fixed number of times and then succeeds, so a
 * test can prove the wrapper is still usable after a failure.
 */
final class ThrowingMailer implements MailerInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly Throwable $failure,
        private readonly int $failuresBeforeSuccess = PHP_INT_MAX,
    ) {}

    #[\Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        ++$this->calls;

        if ($this->calls <= $this->failuresBeforeSuccess) {
            throw $this->failure;
        }
    }
}
