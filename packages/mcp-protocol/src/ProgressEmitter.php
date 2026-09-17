<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol;

use Closure;

/**
 * Emits `notifications/progress` for one `tools/call`, tied to the
 * progress token that call carried.
 *
 * report() invokes the transport's emit closure synchronously, inline, on
 * the tool's own call stack — nothing here pauses the tool, so no
 * coroutine or suspension is involved. A call that carried no token, or a
 * transport that cannot carry a notification, leaves $emit null and every
 * report() is a no-op, so tool code can always call it without asking
 * which context it is in.
 *
 * `total` and `message` are omitted from the notification when not given
 * rather than written as null: an absent optional and an explicit null
 * are different values, and only the first is what "not reported" means.
 */
final readonly class ProgressEmitter
{
    /**
     * @param Closure(array<string, mixed>): void|null $emit receives one
     *        complete JSON-RPC notification message to send
     */
    public function __construct(
        private ?Closure $emit = null,
        private int|string|null $token = null,
    ) {}

    public function report(int|float $progress, int|float|null $total = null, ?string $message = null): void
    {
        if ($this->emit === null) {
            return;
        }

        $params = ['progressToken' => $this->token, 'progress' => $progress];

        if ($total !== null) {
            $params['total'] = $total;
        }

        if ($message !== null) {
            $params['message'] = $message;
        }

        ($this->emit)(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => $params]);
    }
}
