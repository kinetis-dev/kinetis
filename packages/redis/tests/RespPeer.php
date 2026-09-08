<?php

declare(strict_types=1);

namespace Kinetis\Redis\Tests;

use Amp\ByteStream\ResourceStream;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Socket\ServerSocket;
use Amp\Socket\Socket;
use Kinetis\Redis\Endpoint;
use Revolt\EventLoop;

use function Amp\delay;

/**
 * A Redis server for one test: it listens on a loopback port, records
 * every command it receives, and answers from a script the test wrote
 * in advance.
 *
 * A reply of null drops the connection after the command is recorded,
 * and a reply of RespPeer::SILENCE records the command and never
 * answers, which is what proves the non-replay and budget rules without
 * reaching into the link's private state. RespPeer::after() delays one
 * reply, for the tests that need a second fiber to arrive while the
 * first is still waiting.
 *
 * A peer constructed with $drains false accepts connections and never
 * reads a byte, so the kernel's buffers fill and the client's own write
 * suspends. That is the only way to reach write backpressure from a
 * test.
 *
 * Every socket the peer owns is unreferenced, so whether
 * `EventLoop::run()` returns is decided by the link under test alone.
 */
final class RespPeer
{
    public const string SILENCE = "\0silence";

    private const string DELAY = "\0after:";

    private readonly ServerSocket $server;

    /** @var list<list<string>> */
    private array $received = [];

    /** @var list<?string> */
    private array $script;

    private int $connections = 0;

    private int $disconnects = 0;

    /** @var list<Socket> connections held open but never read */
    private array $held = [];

    /**
     * @param list<?string> $script replies in the order commands arrive
     * @param bool $drains false to accept connections and never read them
     */
    public function __construct(array $script = [], private readonly bool $drains = true)
    {
        $this->script = $script;
        $this->server = new ResourceServerSocketFactory()->listen('127.0.0.1:0');
        self::detach($this->server);

        EventLoop::queue(function (): void {
            while ($socket = $this->server->accept()) {
                $this->connections++;
                self::detach($socket);
                EventLoop::queue(fn () => $this->serve($socket));
            }
        });
    }

    /**
     * Appends replies once the peer is listening, for a script that has
     * to name an address the peer only has after construction.
     *
     * @param list<?string> $replies
     */
    public function replies(array $replies): self
    {
        $this->script = [...$this->script, ...$replies];

        return $this;
    }

    public function endpoint(): Endpoint
    {
        return Endpoint::parse($this->server->getAddress()->toString());
    }

    /** @return list<list<string>> every command received, in arrival order */
    public function received(): array
    {
        return $this->received;
    }

    /** @return list<string> the command names received, in arrival order */
    public function commands(): array
    {
        return array_map(static fn (array $frame): string => $frame[0], $this->received);
    }

    public function connections(): int
    {
        return $this->connections;
    }

    /** Connections the client closed, or that ended on a scripted drop. */
    public function disconnects(): int
    {
        return $this->disconnects;
    }

    public function close(): void
    {
        $this->server->close();

        foreach ($this->held as $socket) {
            $socket->close();
        }

        $this->held = [];
    }

    public static function bulk(string $value): string
    {
        return '$' . strlen($value) . "\r\n{$value}\r\n";
    }

    public static function error(string $message): string
    {
        return "-{$message}\r\n";
    }

    /** The same reply, written $seconds after the command arrives. */
    public static function after(float $seconds, string $reply): string
    {
        return self::DELAY . $seconds . "\0" . $reply;
    }

    private static function detach(object $stream): void
    {
        if ($stream instanceof ResourceStream) {
            $stream->unreference();
        }
    }

    private function serve(Socket $socket): void
    {
        if (!$this->drains) {
            $this->held[] = $socket;

            return;
        }

        $buffer = '';

        try {
            while (null !== $chunk = $socket->read()) {
                $buffer .= $chunk;

                while (null !== $frame = self::takeFrame($buffer)) {
                    $this->received[] = $frame;
                    $reply = array_shift($this->script);

                    if ($reply === null) {
                        $this->disconnects++;
                        $socket->close();

                        return;
                    }

                    if (str_starts_with($reply, self::DELAY)) {
                        [$seconds, $reply] = explode("\0", substr($reply, strlen(self::DELAY)), 2);
                        // Unreferenced, so a peer's own timer never
                        // decides whether the loop keeps running.
                        delay((float) $seconds, reference: false);
                    }

                    if ($reply !== self::SILENCE) {
                        $socket->write($reply);
                    }
                }
            }
        } catch (\Throwable) {
            // The client closed first; the recorded commands are what matters.
        }

        $this->disconnects++;
        $socket->close();
    }

    /**
     * Reads one complete RESP array of bulk strings out of $buffer,
     * leaving anything after it in place.
     *
     * @return ?list<string>
     */
    private static function takeFrame(string &$buffer): ?array
    {
        if (!str_starts_with($buffer, '*')) {
            return null;
        }

        $offset = strpos($buffer, "\r\n");

        if ($offset === false) {
            return null;
        }

        $count = (int) substr($buffer, 1, $offset - 1);
        $offset += 2;
        $arguments = [];

        for ($i = 0; $i < $count; $i++) {
            if (($buffer[$offset] ?? '') !== '$') {
                return null;
            }

            $lengthEnd = strpos($buffer, "\r\n", $offset);

            if ($lengthEnd === false) {
                return null;
            }

            $length = (int) substr($buffer, $offset + 1, $lengthEnd - $offset - 1);

            if (strlen($buffer) < $lengthEnd + 2 + $length + 2) {
                return null;
            }

            $arguments[] = substr($buffer, $lengthEnd + 2, $length);
            $offset = $lengthEnd + 2 + $length + 2;
        }

        $buffer = substr($buffer, $offset);

        return $arguments;
    }
}
