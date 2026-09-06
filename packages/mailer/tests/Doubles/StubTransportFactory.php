<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests\Doubles;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A factory a fixture registry entry names, reproducing whichever
 * behaviour no official factory has and a refusal test needs: a
 * `supports()` that throws or declines, a `create()` that throws, or a
 * transport of a class the entry does not name.
 *
 * The static hooks are how a registry entry, which carries a class name
 * rather than an instance, reaches a per-test decision.
 */
final class StubTransportFactory implements TransportFactoryInterface
{
    /** @var null|callable(Dsn): bool */
    public static $supports = null;

    /** @var null|callable(Dsn): TransportInterface */
    public static $create = null;

    public static ?HttpClientInterface $lastClient = null;

    public static int $created = 0;

    public function __construct(
        mixed $dispatcher = null,
        ?HttpClientInterface $client = null,
        mixed $logger = null,
    ) {
        self::$lastClient = $client;
    }

    public static function reset(): void
    {
        self::$supports = null;
        self::$create = null;
        self::$lastClient = null;
        self::$created = 0;
    }

    #[\Override]
    public function supports(Dsn $dsn): bool
    {
        return self::$supports === null ? true : (self::$supports)($dsn);
    }

    #[\Override]
    public function create(Dsn $dsn): TransportInterface
    {
        ++self::$created;

        if (self::$create === null) {
            throw new \LogicException('This stub was not given a transport to return.');
        }

        return (self::$create)($dsn);
    }
}
