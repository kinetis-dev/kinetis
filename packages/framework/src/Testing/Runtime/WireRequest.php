<?php

declare(strict_types=1);

namespace Kinetis\Testing\Runtime;

/**
 * A request as it exists on the wire, before any runtime adapter has
 * seen it — the transport-neutral input every {@see RuntimeAdapterDriver}
 * translates into its own environment's shape (superglobals and
 * `php://input`, an API Gateway event, ...).
 *
 * Headers are a list of pairs rather than a map so a header repeated on
 * the wire stays repeated here; whether the environment then folds the
 * repeats is exactly one of the things the conformance suite asserts.
 * Cookies are kept apart from headers for the same reason: payload
 * format 2.0 carries them outside `headers`, superglobals carry them in
 * `$_COOKIE`, and the suite asserts both end up in the same place.
 */
final readonly class WireRequest
{
    /**
     * @param list<array{0: string, 1: string}> $headers
     * @param list<string> $cookies each entry a single `name=value` pair
     */
    public function __construct(
        public string $method = 'GET',
        public string $path = '/',
        public string $queryString = '',
        public array $headers = [],
        public array $cookies = [],
        public string $body = '',
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public static function json(string $method, string $path, string $body, array $headers = []): self
    {
        $pairs = [['Content-Type', 'application/json']];

        foreach ($headers as $name => $value) {
            $pairs[] = [$name, $value];
        }

        return new self($method, $path, headers: $pairs, body: $body);
    }
}
