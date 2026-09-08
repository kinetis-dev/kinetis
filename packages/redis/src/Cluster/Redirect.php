<?php

declare(strict_types=1);

namespace Kinetis\Redis\Cluster;

use Amp\Redis\Protocol\ProtocolException;
use InvalidArgumentException;
use Kinetis\Redis\Endpoint;

/**
 * A parsed "MOVED 3999 10.0.0.2:6379" or "ASK 3999 [2001:db8::10]:6379"
 * error reply.
 *
 * A message that names neither keyword is not a redirect and comes back
 * as null, so an ordinary Redis error keeps its own handling. A message
 * that names one and then fails to parse is a protocol failure, not an
 * ordinary error that happens to start with the same word.
 */
final class Redirect
{
    private function __construct(
        public readonly RedirectKind $kind,
        public readonly int $slot,
        public readonly Endpoint $target,
    ) {}

    public static function tryParse(string $message): ?self
    {
        $parts = explode(' ', $message, 3);
        $kind = RedirectKind::tryFrom($parts[0]);

        if ($kind === null) {
            return null;
        }

        if (count($parts) !== 3 || !ctype_digit($parts[1]) || (int) $parts[1] >= HashSlot::COUNT) {
            throw new ProtocolException("Malformed {$kind->value} redirect: \"{$message}\".");
        }

        try {
            $target = Endpoint::parse($parts[2]);
        } catch (InvalidArgumentException $e) {
            throw new ProtocolException("Malformed {$kind->value} redirect target: \"{$parts[2]}\".", 0, $e);
        }

        return new self($kind, (int) $parts[1], $target);
    }
}
