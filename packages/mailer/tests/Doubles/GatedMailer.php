<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests\Doubles;

use Amp\DeferredFuture;
use Amp\Future;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A delegate whose sends suspend until the test resumes them, one gate
 * per call, and which reports each entry through a future of its own —
 * so "how many sends are inside the delegate at once" and "the second
 * send has entered" are facts a test awaits rather than moments it
 * guesses at.
 */
final class GatedMailer implements MailerInterface
{
    public int $inside = 0;

    public int $peak = 0;

    public int $completed = 0;

    public int $calls = 0;

    /** @var list<Future<null>> */
    private array $gates;

    /** @var array<int, DeferredFuture<null>> */
    private array $entries = [];

    /**
     * @param list<Future<null>> $gates awaited in call order
     */
    public function __construct(array $gates = [])
    {
        $this->gates = $gates;
    }

    /**
     * Completes once the given call, counted from 1, has entered the
     * delegate.
     *
     * @return Future<null>
     */
    public function entered(int $call): Future
    {
        return $this->entry($call)->getFuture();
    }

    #[\Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $call = ++$this->calls;
        ++$this->inside;
        $this->peak = max($this->peak, $this->inside);
        $this->entry($call)->complete(null);

        try {
            array_shift($this->gates)?->await();
            ++$this->completed;
        } finally {
            --$this->inside;
        }
    }

    /**
     * @return DeferredFuture<null>
     */
    private function entry(int $call): DeferredFuture
    {
        return $this->entries[$call] ??= new DeferredFuture();
    }
}
