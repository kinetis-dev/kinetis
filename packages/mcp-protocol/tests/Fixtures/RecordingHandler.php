<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol\Tests\Fixtures;

use Closure;
use Kinetis\McpProtocol\MessageHandler;

/**
 * A handler that records every message it was given and answers with
 * whatever the test queued — the seam for asserting what the stdio loop
 * decodes and forwards, without the server's own rules in between.
 */
final class RecordingHandler implements MessageHandler
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    /**
     * @param list<array<string, mixed>|null> $responses answered in order;
     *        a message past the end is answered with null
     * @param list<array<string, mixed>> $notifications emitted before each
     *        response
     */
    public function __construct(
        private array $responses = [],
        private readonly array $notifications = [],
    ) {}

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function handle(array $message, ?Closure $emit = null, ?object $context = null): ?array
    {
        $this->messages[] = $message;

        if ($emit !== null) {
            foreach ($this->notifications as $notification) {
                $emit($notification);
            }
        }

        return array_shift($this->responses);
    }
}
