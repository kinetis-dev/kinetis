<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests\Support;

use Kinetis\AwsSigV4\SignedTransport;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Throwable;

/**
 * Transports that fail the way a real one does, one per way PSR-18
 * splits a failure.
 *
 * `connectivity()` and `timeout()` are what a name that will not resolve
 * and an endpoint that never answers raise, and Symfony's PSR-18 adapter
 * reports both as a `NetworkExceptionInterface`. `rejectedRequest()` is
 * what an option or a URL the transport will not accept raises, which
 * that same adapter reports as a `RequestExceptionInterface` — the
 * distinction a caller decides whether to retry on.
 *
 * Every message repeats the URL and every header the transport was
 * handed, so a test can prove the signed `Authorization` and
 * `X-Amz-Security-Token` do not survive into what this package raises.
 */
final class FailingTransport
{
    public const string FAILURE_MESSAGE = 'TRANSPORT-FAILURE-SENTINEL';

    public static function connectivity(): SignedTransport
    {
        return self::throwing(static fn (string $detail): Throwable => new TransportException($detail));
    }

    public static function timeout(): SignedTransport
    {
        return self::throwing(static fn (string $detail): Throwable => new TimeoutException($detail));
    }

    public static function rejectedRequest(): SignedTransport
    {
        return self::throwing(static fn (string $detail): Throwable => new InvalidArgumentException($detail));
    }

    /**
     * @param callable(string): Throwable $failure
     */
    private static function throwing(callable $failure): SignedTransport
    {
        return SignedTransport::answeredInProcess(
            static function (string $method, string $url, array $options) use ($failure): never {
                /** @var list<string> $headers */
                $headers = $options['headers'] ?? [];

                throw $failure(self::FAILURE_MESSAGE . ' ' . $url . ' ' . implode(' ', $headers));
            },
        );
    }
}
