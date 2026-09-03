<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Cluster;

use Amp\Redis\RedisException;
use Kinetis\SimpleCache\Exception\InvalidArgumentException;

/**
 * A parsed MOVED/ASK redirect reply — "MOVED 3999 127.0.0.1:6381" or
 * "ASK 3999 [2001:db8::10]:6379" — never trusted from a bare string
 * prefix. tryParse() checks the kind, the slot (0-16383), and the
 * target endpoint (reusing ClusterEndpoint's own bracketed-IPv6
 * grammar, since a redirect target is written in exactly that form)
 * structurally, not just that the message starts with the right word.
 *
 * A message that isn't a redirect at all returns null, so a caller can
 * fall through to ordinary error handling. A message that names a
 * redirect kind but has a malformed slot or target throws RedisException
 * — this is Redis's own reply, not something the application configured
 * wrong, the same distinction ClusterEndpoint::fromParts() already
 * draws for a malformed CLUSTER SHARDS field.
 */
final class ClusterRedirect
{
    private function __construct(
        public readonly ClusterRedirectKind $kind,
        public readonly int $slot,
        public readonly ClusterEndpoint $target,
    ) {}

    public static function tryParse(string $message): ?self
    {
        $firstSpace = strpos($message, ' ');
        $firstToken = $firstSpace === false ? $message : substr($message, 0, $firstSpace);
        $kind = ClusterRedirectKind::tryFrom($firstToken);

        if ($kind === null) {
            return null;
        }

        // The first token exactly matches a redirect keyword — from here
        // on, any further malformation (a wrong part count, a bad slot,
        // a bad endpoint) is a malformed redirect, not silently treated
        // as an unrelated ordinary failure that merely starts with the
        // same word.
        $parts = explode(' ', $message, 3);

        if (count($parts) !== 3) {
            throw new RedisException(
                "Malformed {$kind->value} redirect: expected \"{$kind->value} <slot> <endpoint>\", got \"{$message}\".",
            );
        }

        [, $slotPart, $targetPart] = $parts;

        if (!ctype_digit($slotPart)) {
            throw new RedisException("Malformed {$kind->value} redirect: invalid slot \"{$slotPart}\".");
        }

        $slot = (int) $slotPart;

        if ($slot > 16383) {
            throw new RedisException("Malformed {$kind->value} redirect: slot {$slot} is outside the valid 0-16383 range.");
        }

        try {
            $target = ClusterEndpoint::parse($targetPart);
        } catch (InvalidArgumentException $e) {
            throw new RedisException("Malformed {$kind->value} redirect: invalid target endpoint \"{$targetPart}\".", previous: $e);
        }

        return new self($kind, $slot, $target);
    }
}
