<?php

declare(strict_types=1);

namespace Kinetis\Redis\Tests;

use Amp\NullCancellation;
use Amp\Redis\Protocol\QueryException;
use InvalidArgumentException;
use Kinetis\Redis\Client;
use Kinetis\Redis\ClientOptions;
use Kinetis\Redis\ClusterClient;
use Kinetis\Redis\Endpoint;
use Kinetis\Redis\Exception\ConnectionFailed;
use Kinetis\Redis\Exception\OutcomeUnknown;
use Kinetis\Redis\Exception\RedirectLimitExceeded;
use Kinetis\Redis\Internal\Connection;
use Kinetis\Redis\QueryExecutor;
use Kinetis\Redis\RoutedExecutor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;
use SplObjectStorage;

use function Amp\Socket\createSocketPair;

/**
 * What a failure from this package is allowed to carry, checked while
 * the caller's own command is still on the stack.
 *
 * Each test plants a sentinel in a routing key, a value, a script or a
 * password, fails the operation at a point where the frames holding it
 * are live, and then follows the whole exception chain — messages,
 * rendered traces, trace arguments, the properties of the objects among
 * them, and the variables their closures captured — looking for it.
 *
 * The suite runs with `zend.exception_ignore_args=0`, the setting that
 * puts call arguments into traces. Left at its default, every one of
 * these assertions would pass without an annotation doing any work.
 *
 * Peers and clients stay local to a test method. PHPUnit reaches the
 * test case itself through its own trace frames, and a peer holds every
 * command it received, so a peer on a property would be found and would
 * say nothing about what this package retains.
 */
final class TraceSecrecyTest extends TestCase
{
    private const string SENTINEL = 'SENTINEL';

    private const string KEY = 'SENTINELKEY';

    private const string VALUE = 'SENTINELVALUE';

    private const string SCRIPT = "return 'SENTINELSCRIPT'";

    private const string PASSWORD = 'SENTINELPASS';

    private string|false $restoreIgnoreArgs = false;

    protected function setUp(): void
    {
        $this->restoreIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->restoreIgnoreArgs === false ? '1' : $this->restoreIgnoreArgs);
    }

    /**
     * The budget expires with the command written and unanswered, so
     * every frame from execute() down to the write is still on the stack
     * when the failure is raised.
     */
    #[Test]
    public function a_command_that_never_gets_a_reply_keeps_its_key_and_value_off_the_trace(): void
    {
        $peer = new RespPeer([RespPeer::SILENCE]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 0.3));

        try {
            $client->execute('SET', self::KEY, self::VALUE, 'EX', 60);
            self::fail('Expected the unanswered command to spend the budget.');
        } catch (OutcomeUnknown $e) {
            self::assertNoSentinelAnywhereIn($e);
            // The redaction is what empties those frames, not an empty
            // trace: at least one argument on the way down is the marker
            // PHP leaves where it stopped holding a value.
            self::assertGreaterThan(0, self::walk($e->getTrace(), new SplObjectStorage()));
        } finally {
            $client->close();
            $peer->close();
        }
    }

    /**
     * The write itself fails, with the encoded command held as the
     * unmarked argument of amphp/byte-stream's own write frame. Nothing
     * of that exception reaches the failure this package raises: it is
     * not chained, and its message is not quoted.
     */
    #[Test]
    public function a_write_that_fails_on_the_socket_keeps_the_encoded_command_off_the_trace(): void
    {
        [$near, $far] = createSocketPair();
        $connection = new Connection($near);
        $connection->close();

        try {
            $connection->write(Connection::encode('SET', [self::KEY, self::VALUE]), new NullCancellation());
            self::fail('Expected the write on a closed socket to fail.');
        } catch (OutcomeUnknown $e) {
            self::assertSame('The Redis connection failed while the command was being written.', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertNoSentinelAnywhereIn($e);
            self::assertGreaterThan(0, self::walk($e->getTrace(), new SplObjectStorage()));
        } finally {
            $far->close();
        }
    }

    /**
     * The same write frame, reached the way a deployment reaches it: a
     * peer that never reads suspends the write until the budget ends it.
     * The sentinel is at the end of the value, which is the part of the
     * payload amphp/byte-stream still holds when it gives up.
     */
    #[Test]
    public function a_write_the_budget_ends_keeps_the_value_it_was_sending_off_the_trace(): void
    {
        $peer = new RespPeer([], drains: false);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 0.3));

        try {
            $client->execute('SET', self::KEY, str_repeat('x', 4 * 1024 * 1024) . self::VALUE);
            self::fail('Expected the undrained write to spend the budget.');
        } catch (OutcomeUnknown $e) {
            self::assertSame(
                'The Redis operation budget expired while the command was being written.',
                $e->getMessage(),
            );
            self::assertNull($e->getPrevious());
            self::assertNoSentinelAnywhereIn($e);
            self::assertGreaterThan(0, self::walk($e->getTrace(), new SplObjectStorage()));
        } finally {
            $client->close();
            $peer->close();
        }
    }

    /**
     * AUTH travels the same write. A password long enough to fill the
     * kernel's buffers against a peer that never reads is what fails
     * that write with the credential live on the stack.
     */
    #[Test]
    public function an_auth_write_the_budget_ends_keeps_the_password_off_the_trace(): void
    {
        $peer = new RespPeer([], drains: false);
        $client = Client::create($peer->endpoint(), new ClientOptions(
            timeout: 0.3,
            password: str_repeat('x', 4 * 1024 * 1024) . self::PASSWORD,
        ));

        try {
            $client->execute('GET', self::KEY);
            self::fail('Expected the undrained AUTH to spend the budget.');
        } catch (ConnectionFailed $e) {
            self::assertNoSentinelAnywhereIn($e);
        } finally {
            $client->close();
            $peer->close();
        }
    }

    /** A Redis error reply raises while pipeline() still holds the command. */
    #[Test]
    public function an_error_reply_keeps_the_command_that_earned_it_off_the_trace(): void
    {
        $peer = new RespPeer([RespPeer::error('WRONGTYPE Operation against a key holding the wrong kind of value')]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 2.0));

        try {
            $client->execute('APPEND', self::KEY, self::VALUE);
            self::fail('Expected the error reply to raise.');
        } catch (QueryException $e) {
            self::assertNoSentinelAnywhereIn($e);
        } finally {
            $client->close();
            $peer->close();
        }
    }

    /** The script, its keys and its arguments travel the same chain. */
    #[Test]
    public function a_script_that_never_gets_a_reply_keeps_its_body_and_keys_off_the_trace(): void
    {
        $peer = new RespPeer([RespPeer::SILENCE]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 0.3));

        try {
            $client->script(self::KEY, self::SCRIPT, [self::KEY], [self::VALUE]);
            self::fail('Expected the unanswered script to spend the budget.');
        } catch (OutcomeUnknown $e) {
            self::assertNoSentinelAnywhereIn($e);
        } finally {
            $client->close();
            $peer->close();
        }
    }

    /** The transport a consumer reaches through link() answers for itself. */
    #[Test]
    public function a_command_sent_straight_at_the_link_keeps_its_parameters_off_the_trace(): void
    {
        $peer = new RespPeer([RespPeer::SILENCE]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 0.3));

        try {
            $client->link()->execute('SET', [self::KEY, self::VALUE]);
            self::fail('Expected the unanswered command to spend the budget.');
        } catch (OutcomeUnknown $e) {
            self::assertNoSentinelAnywhereIn($e);
        } finally {
            $client->close();
            $peer->close();
        }
    }

    /**
     * The cluster path adds two frames a single node has not: follow(),
     * holding the closure that captured the command and its arguments,
     * and the closure itself, holding the node client whose options
     * carry the password.
     */
    #[Test]
    public function a_cluster_command_keeps_its_key_value_and_password_off_the_trace(): void
    {
        $owner = new RespPeer(["+OK\r\n", RespPeer::SILENCE]);
        $seed = new RespPeer(["+OK\r\n", self::slots($owner)]);
        $client = ClusterClient::create(
            [$seed->endpoint()],
            new ClientOptions(timeout: 0.5, password: self::PASSWORD),
        );

        try {
            $client->executeKeyed(self::KEY, 'SET', self::KEY, self::VALUE);
            self::fail('Expected the unanswered command to spend the budget.');
        } catch (OutcomeUnknown $e) {
            self::assertNoSentinelAnywhereIn($e);
            self::assertGreaterThan(0, self::walk($e->getTrace(), new SplObjectStorage()));
        } finally {
            $client->close();
            $owner->close();
            $seed->close();
        }
    }

    /** The same two frames, with a script's body in them. */
    #[Test]
    public function a_cluster_script_keeps_its_body_and_password_off_the_trace(): void
    {
        $owner = new RespPeer(["+OK\r\n", RespPeer::SILENCE]);
        $seed = new RespPeer(["+OK\r\n", self::slots($owner)]);
        $client = ClusterClient::create(
            [$seed->endpoint()],
            new ClientOptions(timeout: 0.5, password: self::PASSWORD),
        );

        try {
            $client->script(self::KEY, self::SCRIPT, [self::KEY], [self::VALUE]);
            self::fail('Expected the unanswered script to spend the budget.');
        } catch (OutcomeUnknown $e) {
            self::assertNoSentinelAnywhereIn($e);
        } finally {
            $client->close();
            $owner->close();
            $seed->close();
        }
    }

    /**
     * follow() gives up holding the closure that captured the command,
     * so the redirect bound has to be reached with that frame clean.
     */
    #[Test]
    public function a_command_redirected_past_the_bound_keeps_its_key_and_value_off_the_trace(): void
    {
        $first = new RespPeer();
        $second = new RespPeer();
        $first->replies(array_fill(0, 6, RespPeer::error('MOVED 0 ' . $second->endpoint()->authority())));
        $second->replies(array_fill(0, 6, RespPeer::error('MOVED 0 ' . $first->endpoint()->authority())));
        $seed = new RespPeer([self::slots($first)]);
        $client = ClusterClient::create([$seed->endpoint()], new ClientOptions(timeout: 2.0));

        try {
            $client->executeKeyed(self::KEY, 'SET', self::KEY, self::VALUE);
            self::fail('Expected the redirect bound to be reached.');
        } catch (RedirectLimitExceeded $e) {
            self::assertNoSentinelAnywhereIn($e);
            self::assertGreaterThan(0, self::walk($e->getTrace(), new SplObjectStorage()));
        } finally {
            $client->close();
            $first->close();
            $second->close();
            $seed->close();
        }
    }

    /**
     * create() refuses a non-zero database before it has constructed
     * anything, so the options it was handed — password and all — are
     * the live frame's own argument.
     */
    #[Test]
    public function a_cluster_refused_for_its_database_keeps_the_password_off_the_trace(): void
    {
        $options = new ClientOptions(timeout: 1.0, password: self::PASSWORD, database: 3);

        try {
            ClusterClient::create([Endpoint::parse('10.0.0.1:6379')], $options);
            self::fail('Expected a non-zero database to be refused.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('database 0 only', $e->getMessage());
            self::assertNoSentinelAnywhereIn($e);
            self::assertGreaterThan(0, self::walk($e->getTrace(), new SplObjectStorage()));
        }
    }

    /**
     * An implementation's marks are what the tests above prove; the
     * interfaces have to carry the same ones, so a consumer writing its
     * own executor reads the contract rather than inferring it.
     */
    #[Test]
    public function the_executor_interfaces_carry_the_marks_their_implementations_prove(): void
    {
        $pairs = [
            [QueryExecutor::class, Client::class],
            [RoutedExecutor::class, Client::class],
            [RoutedExecutor::class, ClusterClient::class],
        ];

        foreach ($pairs as [$interface, $implementation]) {
            foreach (new \ReflectionClass($interface)->getMethods() as $method) {
                self::assertSame(
                    self::sensitiveParameters($implementation, $method->getName()),
                    self::sensitiveParameters($interface, $method->getName()),
                    "{$interface}::{$method->getName()} against {$implementation}",
                );
            }
        }
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private static function sensitiveParameters(string $class, string $method): array
    {
        $marked = [];

        foreach (new \ReflectionMethod($class, $method)->getParameters() as $parameter) {
            if ($parameter->getAttributes(\SensitiveParameter::class) !== []) {
                $marked[] = $parameter->getName();
            }
        }

        return $marked;
    }

    /** The message and the rendered trace of every link of the chain. */
    private static function assertNoSentinelAnywhereIn(\Throwable $failure): void
    {
        for ($current = $failure; $current !== null; $current = $current->getPrevious()) {
            self::assertStringNotContainsString(self::SENTINEL, $current->getMessage(), $current::class);
            self::assertStringNotContainsString(self::SENTINEL, $current->getTraceAsString(), $current::class);
            self::walk($current->getTrace(), new SplObjectStorage(), $current::class);
        }
    }

    /**
     * Everything a trace holds, followed to the end: an argument, an
     * object's own properties, an array's keys and values, and the
     * variables a closure captured. Nothing reachable that way may carry
     * a sentinel.
     *
     * A `SensitiveParameterValue` is where PHP itself stopped holding an
     * argument, so it is counted rather than descended into. The count
     * is returned so a caller can assert the redaction is what emptied
     * the trace rather than the trace having been empty to begin with.
     *
     * @param SplObjectStorage<object, null> $seen
     */
    private static function walk(mixed $value, SplObjectStorage $seen, string $path = 'trace'): int
    {
        if (is_string($value)) {
            self::assertFalse(str_contains($value, self::SENTINEL), "A sentinel is reachable at {$path}.");

            return 0;
        }

        if ($value instanceof SensitiveParameterValue) {
            return 1;
        }

        if (is_object($value)) {
            if ($seen->contains($value)) {
                return 0;
            }

            $seen->attach($value);
            $path .= '{' . $value::class . '}';
            $value = $value instanceof \Closure
                ? new \ReflectionFunction($value)->getClosureUsedVariables()
                : (array) $value;
        }

        if (!is_array($value)) {
            return 0;
        }

        $redacted = 0;

        foreach ($value as $key => $nested) {
            if (is_string($key)) {
                self::assertFalse(str_contains($key, self::SENTINEL), "A sentinel is a key at {$path}.");
            }

            $redacted += self::walk($nested, $seen, "{$path}.{$key}");
        }

        return $redacted;
    }

    /** A CLUSTER SLOTS reply giving one master the whole keyspace. */
    private static function slots(RespPeer $master): string
    {
        $endpoint = $master->endpoint();

        return "*1\r\n*3\r\n:0\r\n:16383\r\n*3\r\n"
            . RespPeer::bulk($endpoint->host)
            . ":{$endpoint->port}\r\n"
            . RespPeer::bulk(str_repeat('a', 40));
    }
}
