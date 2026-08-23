<?php

declare(strict_types=1);

namespace Kinetis\Testing\Runtime;

/**
 * The response as the environment observed it leaving the adapter — real
 * bytes on a socket for the superglobals adapters, the decoded Runtime
 * API payload for Lambda. Assertions run against this, never against the
 * PSR-7 object the handler returned: the adapter's job is to get that
 * object out intact, and only the far side can tell whether it did.
 */
final readonly class WireResponse
{
    /**
     * @param list<array{0: string, 1: string}> $headers every header except
     *     Set-Cookie, repeats preserved as separate pairs
     * @param list<string> $setCookies each `Set-Cookie` value on its own
     * @param float|null $bodyArrivalSpanSeconds seconds between the first
     *     body byte reaching the client and the last — the only evidence,
     *     from the receiving side, of whether a streamed response was
     *     delivered as it was written or held until the end. Null when
     *     the driver has no wire to time (an in-process payload).
     */
    public function __construct(
        public int $status,
        public array $headers,
        public array $setCookies,
        public string $body,
        public ?float $bodyArrivalSpanSeconds = null,
    ) {}

    /**
     * @return list<string>
     */
    public function header(string $name): array
    {
        $values = [];

        foreach ($this->headers as [$stored, $value]) {
            if (strcasecmp($stored, $name) === 0) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
