<?php

declare(strict_types=1);

namespace Kinetis\Tests\Linting;

use Kinetis\Linting\NoBlockingIoRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Each analysis runs in its own process: in the suite's shared process, a
 * cold-cache analysis of these fixtures exceeds PHP's default memory limit,
 * and the negative case defines App\sleep() for the rest of its process.
 *
 * @extends RuleTestCase<NoBlockingIoRule>
 */
#[RunTestsInSeparateProcesses]
final class NoBlockingIoRuleTest extends RuleTestCase
{
    private const string SLEEP = '%s blocks the worker and its whole event loop until it returns. '
        . 'Use Kinetis\Async\Timer::delay() inside a Fiber, such as a concurrently() task; '
        . 'outside one PHP throws FiberError.';

    private const string SOCKET = '%s opens a socket whose connect, reads, and writes block the worker and its '
        . 'whole event loop. Use Kinetis\Async\Socket or another Revolt-aware socket, or '
        . 'kinetis/revolt-http-client for HTTP.';

    private const string CURL = '%s waits for the transfer and blocks the worker and its whole event loop. '
        . 'Use kinetis/revolt-http-client, which suspends only the calling Fiber.';

    private const string DATABASE = '%s opens a database connection whose queries block the worker and its whole '
        . 'event loop. Inject a kinetis/persistence link contract (Kinetis\Persistence\Contract\SqlLink, '
        . 'MysqlLink, or PostgresLink), whose driver matches the runtime.';

    private const string PROCESS = '%s runs a child process through blocking waits that stop the worker and its '
        . 'whole event loop. Keep child processes out of persistent workers: hand the work to a separate '
        . 'short-lived or external process, or run it from an audited short-lived console command.';

    private const string HTTP = '%s picks an HTTP transport that is not guaranteed to be Revolt-backed; a blocking '
        . 'one stops the worker and its whole event loop for every request. Inject Kinetis\RevoltHttpClient\Http, '
        . 'or give the library an explicit client from Kinetis\RevoltHttpClient\AmpHttpClientFactory::create().';

    protected function getRule(): Rule
    {
        return new NoBlockingIoRule(self::createReflectionProvider());
    }

    public function test_flags_every_admitted_category_through_imports_and_aliases(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/BlockingIoCalls.php'], [
            [sprintf(self::SLEEP, 'sleep()'), 21],
            [sprintf(self::SLEEP, 'usleep()'), 22],
            [sprintf(self::SLEEP, 'time_nanosleep()'), 23],
            [sprintf(self::SLEEP, 'time_sleep_until()'), 24],
            [sprintf(self::SOCKET, 'fsockopen()'), 29],
            [sprintf(self::SOCKET, 'pfsockopen()'), 30],
            [sprintf(self::SOCKET, 'stream_socket_client()'), 31],
            [sprintf(self::CURL, 'curl_exec()'), 32],
            [sprintf(self::CURL, 'curl_multi_select()'), 33],
            [sprintf(self::DATABASE, 'new PDO'), 38],
            [sprintf(self::DATABASE, 'new mysqli'), 39],
            [sprintf(self::DATABASE, 'mysqli_connect()'), 40],
            [sprintf(self::DATABASE, 'pg_connect()'), 41],
            [sprintf(self::DATABASE, 'pg_pconnect()'), 42],
            [sprintf(self::PROCESS, 'exec()'), 47],
            [sprintf(self::PROCESS, 'shell_exec()'), 48],
            [sprintf(self::PROCESS, 'system()'), 49],
            [sprintf(self::PROCESS, 'passthru()'), 50],
            [sprintf(self::PROCESS, 'proc_open()'), 51],
            [sprintf(self::PROCESS, 'popen()'), 52],
            [sprintf(self::HTTP, 'Http\Discovery\Psr18ClientDiscovery::find()'), 57],
            [sprintf(self::HTTP, 'Http\Discovery\HttpClientDiscovery::find()'), 58],
            [sprintf(self::HTTP, 'Http\Discovery\HttpAsyncClientDiscovery::find()'), 59],
            [sprintf(self::HTTP, 'new Http\Discovery\Psr18Client'), 60],
            [sprintf(self::HTTP, 'new Http\Discovery\Psr18Client'), 61],
            [sprintf(self::HTTP, 'new Http\Discovery\Psr18Client'), 62],
            [sprintf(self::HTTP, 'Symfony\Component\HttpClient\HttpClient::create()'), 63],
            [sprintf(self::HTTP, 'Symfony\Component\HttpClient\HttpClient::createForBaseUri()'), 64],
            [sprintf(self::HTTP, 'new Symfony\Component\HttpClient\HttplugClient'), 65],
            [sprintf(self::HTTP, 'new Symfony\Component\HttpClient\HttplugClient'), 66],
            [sprintf(self::HTTP, 'new Symfony\Component\HttpClient\HttplugClient'), 67],
            [sprintf(self::HTTP, 'new Symfony\Component\HttpClient\Psr18Client'), 68],
            [sprintf(self::HTTP, 'new Symfony\Component\HttpClient\Psr18Client'), 69],
            [sprintf(self::HTTP, 'new Symfony\Component\HttpClient\Psr18Client'), 70],
            [sprintf(self::HTTP, 'new GuzzleHttp\Client'), 71],
        ]);
    }

    public function test_does_not_flag_a_namespaced_function_an_explicit_transport_or_unrelated_calls(): void
    {
        // The test container reflects functions PHP has loaded, not ones
        // declared in an analysed file, so App\sleep() must exist here for
        // name resolution to find it the way a project analysis does.
        require_once __DIR__ . '/Fixtures/NonBlockingIoCalls.php';

        $this->analyse([__DIR__ . '/Fixtures/NonBlockingIoCalls.php'], []);
    }
}
