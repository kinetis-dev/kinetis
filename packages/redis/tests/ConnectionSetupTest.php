<?php

declare(strict_types=1);

namespace Kinetis\Redis\Tests;

use Amp\Socket\ClientTlsContext;
use InvalidArgumentException;
use Kinetis\Redis\Client;
use Kinetis\Redis\ClientOptions;
use Kinetis\Redis\ConnectionUri;
use Kinetis\Redis\Endpoint;
use Kinetis\Redis\Exception\ConnectionFailed;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class ConnectionSetupTest extends TestCase
{
    private const string PASSWORD = 'sw0rdf1sh';

    #[Test]
    public function auth_and_select_run_before_the_first_command_on_every_connection(): void
    {
        $peer = new RespPeer([
            "+OK\r\n",
            "+OK\r\n",
            RespPeer::bulk('value'),
            null,
            "+OK\r\n",
            "+OK\r\n",
            RespPeer::bulk('again'),
        ]);

        $options = new ClientOptions(timeout: 2.0, password: self::PASSWORD, database: 4);
        $client = Client::create($peer->endpoint(), $options);

        self::assertSame('value', $client->execute('GET', 'k'));

        try {
            $client->execute('GET', 'k');
        } catch (\Throwable) {
            // The peer dropped the connection; the next call reconnects.
        }

        self::assertSame('again', $client->execute('GET', 'k'));
        self::assertSame(
            ['AUTH', 'SELECT', 'GET', 'GET', 'AUTH', 'SELECT', 'GET'],
            $peer->commands(),
        );
        self::assertSame(2, $peer->connections());

        $client->close();
        $peer->close();
    }

    #[Test]
    public function a_rejected_password_fails_the_connection_without_quoting_the_credential(): void
    {
        $peer = new RespPeer([RespPeer::error('WRONGPASS invalid username-password pair or user is disabled.')]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 2.0, password: self::PASSWORD));

        // Traces carry their arguments only while this is off, which is
        // exactly the configuration the password has to survive.
        $ignoreArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            $client->execute('GET', 'k');
            self::fail('Expected AUTH to be rejected.');
        } catch (ConnectionFailed $e) {
            self::assertStringContainsString('AUTH', $e->getMessage());
            self::assertCredentialAbsent($e);
        } finally {
            if ($ignoreArguments !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArguments);
            }
        }

        $client->close();
        $peer->close();
    }

    #[Test]
    public function a_setup_that_fails_mid_flight_keeps_the_password_out_of_its_chain(): void
    {
        // The peer drops the connection with AUTH unanswered, so the
        // failure is raised with the credential still on the stack.
        $peer = new RespPeer([null]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 2.0, password: self::PASSWORD));

        $ignoreArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            $client->execute('GET', 'k');
            self::fail('Expected the dropped connection to fail the setup.');
        } catch (ConnectionFailed $e) {
            self::assertCredentialAbsent($e);
        } finally {
            if ($ignoreArguments !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArguments);
            }
        }

        $client->close();
        $peer->close();
    }

    #[Test]
    public function closing_the_client_while_the_connection_is_being_set_up_never_sends_the_command(): void
    {
        // AUTH is answered, but only after close() has already run.
        $peer = new RespPeer([RespPeer::after(0.2, "+OK\r\n"), RespPeer::bulk('value')]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 5.0, password: self::PASSWORD));

        $pending = async(static fn () => $client->execute('GET', 'k'));
        delay(0.05);

        self::assertSame(['AUTH'], $peer->commands(), 'AUTH should be on the wire and unanswered.');

        $client->close();

        try {
            $pending->await();
            self::fail('Expected the closed link to fail the command.');
        } catch (ConnectionFailed) {
            // The command was never dispatched.
        }

        // The setup reply lands after close(). Its connection is no
        // longer the link's, so it is dropped rather than published and
        // the caller's command is never written on it.
        delay(0.3);
        self::assertSame(['AUTH'], $peer->commands());

        $peer->close();
    }

    #[Test]
    public function a_plaintext_peer_cannot_answer_a_client_configured_for_tls(): void
    {
        $peer = new RespPeer([RespPeer::bulk('value')]);
        $options = new ClientOptions(timeout: 1.0, tls: new ClientTlsContext(''));
        $client = Client::create($peer->endpoint(), $options);

        try {
            $client->execute('GET', 'k');
            self::fail('Expected the TLS handshake to fail against a plaintext peer.');
        } catch (ConnectionFailed $e) {
            self::assertStringContainsString($peer->endpoint()->authority(), $e->getMessage());
        }

        $client->close();
        $peer->close();
    }

    #[Test]
    public function a_uri_carries_the_endpoint_password_and_database(): void
    {
        $parsed = ConnectionUri::parse('redis://:' . self::PASSWORD . '@10.0.0.1:6380/3');

        self::assertSame('10.0.0.1:6380', $parsed->endpoint->authority());
        self::assertSame(self::PASSWORD, $parsed->password);
        self::assertSame(3, $parsed->database);
    }

    #[Test]
    public function a_uri_without_credentials_carries_none(): void
    {
        $parsed = ConnectionUri::parse('redis://cache.internal');

        self::assertSame('cache.internal:6379', $parsed->endpoint->authority());
        self::assertNull($parsed->password);
        self::assertSame(0, $parsed->database);
    }

    #[Test]
    public function a_unix_socket_uri_is_refused_because_it_cannot_be_routed_to(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConnectionUri::parse('unix:///var/run/redis.sock');
    }

    #[Test]
    public function a_malformed_uri_is_reported_without_echoing_its_credentials(): void
    {
        // The URIs stay local to this method: a data provider would put
        // them among the test runner's own trace arguments, which says
        // nothing about what this package keeps.
        $uris = [
            // Refused for its scheme.
            'gopher://:' . self::PASSWORD . '@10.0.0.1:6379',
            // Refused by the URI parser itself — the failure whose
            // vendor message quotes the whole input back, password and
            // all.
            'redis://:' . self::PASSWORD . '@10.0.0.1:not-a-port',
            '://:' . self::PASSWORD . '@10.0.0.1:6379',
        ];

        $ignoreArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            foreach ($uris as $uri) {
                try {
                    ConnectionUri::parse($uri);
                    self::fail('Expected the URI to be refused.');
                } catch (InvalidArgumentException $e) {
                    self::assertNull($e->getPrevious(), 'A cause here would carry the vendor message, which quotes the URI.');
                    self::assertCredentialAbsent($e);
                }
            }
        } finally {
            if ($ignoreArguments !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArguments);
            }
        }
    }

    #[Test]
    public function options_are_immutable_and_validated(): void
    {
        $base = new ClientOptions();
        $tls = new ClientTlsContext('');
        $derived = $base->withTimeout(1.5)->withPassword('p')->withDatabase(2)->withTls($tls);

        self::assertSame(5.0, $base->timeout);
        self::assertNull($base->password);
        self::assertSame(0, $base->database);
        self::assertNull($base->tls);

        self::assertSame(1.5, $derived->timeout);
        self::assertSame('p', $derived->password);
        self::assertSame(2, $derived->database);
        self::assertSame($tls, $derived->tls);
    }

    #[Test]
    public function a_non_positive_budget_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClientOptions(timeout: 0.0);
    }

    #[Test]
    public function a_negative_database_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClientOptions(database: -1);
    }

    #[Test]
    public function the_link_is_the_transport_the_vendor_client_composes_over(): void
    {
        $client = Client::create(Endpoint::parse('127.0.0.1:6379'), new ClientOptions());

        self::assertInstanceOf(\Amp\Redis\Connection\RedisLink::class, $client->link());
        self::assertSame($client->link(), $client->link());
    }

    /**
     * The whole chain, and each link of it as a reader would see it: the
     * message, the rendered trace, and the trace's own arguments.
     */
    private static function assertCredentialAbsent(\Throwable $failure): void
    {
        for ($current = $failure; $current !== null; $current = $current->getPrevious()) {
            self::assertStringNotContainsString(self::PASSWORD, $current->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $current->getTraceAsString());
            self::assertFalse(
                self::holdsCredential($current->getTrace()),
                'A trace argument carries the password: ' . $current::class,
            );
        }
    }

    /** Strings and arrays only — what a trace formatter prints. */
    private static function holdsCredential(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, self::PASSWORD);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::holdsCredential($item)) {
                    return true;
                }
            }
        }

        return false;
    }
}
