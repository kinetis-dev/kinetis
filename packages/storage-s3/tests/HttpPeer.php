<?php

declare(strict_types=1);

namespace Kinetis\StorageS3\Tests;

use Amp\ByteStream\ResourceStream;
use Amp\ByteStream\StreamException;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Socket\ServerSocket;
use Amp\Socket\Socket;
use Revolt\EventLoop;

/**
 * An S3 endpoint for one test: it listens on a loopback port, records
 * each HTTP/1.1 request it receives, and answers a request with a
 * `Content-Length` and no `Transfer-Encoding` with `200 OK`.
 *
 * Any other request is answered `411 Length Required` as soon as its head
 * arrives, as S3 answers a chunked upload, so a length that does not
 * reach the wire fails the test with that status.
 *
 * Every socket the peer owns is unreferenced, so whether
 * `EventLoop::run()` returns is decided by the client under test alone.
 */
final class HttpPeer
{
    private readonly ServerSocket $server;

    /** @var list<array{headers: array<string, string>, body: string}> */
    private array $received = [];

    public function __construct()
    {
        $this->server = new ResourceServerSocketFactory()->listen('127.0.0.1:0');
        self::detach($this->server);

        EventLoop::queue(function (): void {
            while ($socket = $this->server->accept()) {
                self::detach($socket);
                EventLoop::queue(fn () => $this->serve($socket));
            }
        });
    }

    public function endpoint(): string
    {
        return 'http://' . $this->server->getAddress()->toString();
    }

    /**
     * @return list<array{headers: array<string, string>, body: string}>
     *         every request received, header names lower-cased
     */
    public function received(): array
    {
        return $this->received;
    }

    public function close(): void
    {
        $this->server->close();
    }

    private static function detach(object $stream): void
    {
        if ($stream instanceof ResourceStream) {
            $stream->unreference();
        }
    }

    private function serve(Socket $socket): void
    {
        $buffer = '';

        try {
            while (!str_contains($buffer, "\r\n\r\n")) {
                $chunk = $socket->read();

                if ($chunk === null) {
                    return;
                }

                $buffer .= $chunk;
            }

            [$head, $body] = explode("\r\n\r\n", $buffer, 2);
            $headers = [];

            foreach (array_slice(explode("\r\n", $head), 1) as $line) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }

            $length = $headers['content-length'] ?? null;

            if ($length === null || isset($headers['transfer-encoding'])) {
                $this->received[] = ['headers' => $headers, 'body' => $body];
                $socket->write("HTTP/1.1 411 Length Required\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

                // Drained rather than cut off, so the client reads the 411
                // instead of failing its own write on a closed socket.
                while (!str_ends_with($body, "0\r\n\r\n") && null !== $chunk = $socket->read()) {
                    $body .= $chunk;
                }

                return;
            }

            while (strlen($body) < (int) $length && null !== $chunk = $socket->read()) {
                $body .= $chunk;
            }

            $this->received[] = ['headers' => $headers, 'body' => $body];
            $socket->write("HTTP/1.1 200 OK\r\nETag: \"peer\"\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        } catch (StreamException) {
            // The client closed first; the recorded request is what matters.
        } finally {
            $socket->close();
        }
    }
}
