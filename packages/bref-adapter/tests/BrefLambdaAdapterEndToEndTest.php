<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter\Tests;

use Kinetis\BrefAdapter\BrefLambdaAdapter;
use Kinetis\BrefAdapter\Exception\BrefAdapterException;
use Kinetis\Http\Form\FormLimits;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Drives a full invocation through a real BrefLambdaAdapter over a real
 * socket, against tests/Fixtures/fake-runtime-api.php standing in for
 * the Lambda Runtime API — the same "against a real server" discipline
 * every other real-backend proof in this project follows, rather than
 * mocking the Runtime API's own HTTP contract.
 *
 * run()'s own loop is intentionally infinite (see its docblock), so this
 * relies on the fixture server's second .../invocation/next poll
 * returning a failure status — which the fix under test turns into a
 * thrown exception instead of a silent empty body — to stop the loop
 * after exactly one real invocation.
 */
final class BrefLambdaAdapterEndToEndTest extends TestCase
{
    private static function limits(): FormLimits
    {
        return new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES);
    }

    private const HOST = '127.0.0.1:8096';

    /** @var resource */
    private static $serverProcess;

    private static string $stateDir;

    public static function setUpBeforeClass(): void
    {
        self::$stateDir = sys_get_temp_dir() . '/kinetis-bref-e2e-' . bin2hex(random_bytes(8));
        mkdir(self::$stateDir);

        $event = [
            'version' => '2.0',
            'rawPath' => '/dashboard',
            'rawQueryString' => 'tab=billing',
            'headers' => ['content-type' => 'application/json'],
            'cookies' => ['kinetis_session=abc123', 'theme=dark'],
            'queryStringParameters' => ['tab' => 'billing'],
            'requestContext' => [
                'domainName' => 'kinetis.execute-api.eu-west-1.amazonaws.com',
                'http' => ['method' => 'GET', 'protocol' => 'HTTP/1.1', 'sourceIp' => '203.0.113.7'],
            ],
            'body' => '',
            'isBase64Encoded' => false,
        ];
        file_put_contents(self::$stateDir . '/event.json', json_encode($event, JSON_THROW_ON_ERROR));

        self::$serverProcess = proc_open(
            ['php', '-S', self::HOST, __DIR__ . '/Fixtures/fake-runtime-api.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['LAMBDA_TEST_STATE_DIR' => self::$stateDir],
        );

        usleep(300_000);
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$serverProcess);
        proc_close(self::$serverProcess);

        foreach (glob(self::$stateDir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir(self::$stateDir);
    }

    public function test_a_full_invocation_round_trips_through_the_real_runtime_api(): void
    {
        /** @var ServerRequestInterface|null $capturedRequest */
        $capturedRequest = null;

        $adapter = new BrefLambdaAdapter(self::HOST, self::limits());

        try {
            $adapter->run(static function (ServerRequestInterface $request) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $request;

                return (new Response(200, ['Content-Type' => 'text/plain']))
                    ->withAddedHeader('Set-Cookie', 'a=1; Path=/')
                    ->withAddedHeader('Set-Cookie', 'b=2; Path=/')
                    ->withBody(Stream::create("\xFF\x00binary"));
            });

            self::fail('run() should not return normally — the fixture server is expected to stop it via a thrown exception.');
        } catch (BrefAdapterException $e) {
            // Expected, and asserted specifically rather than caught as a
            // bare Throwable: the fixture's second /next poll answers
            // 500, which request() turns into this exact exception type
            // instead of the silent '' it used to return — that's what
            // ends this otherwise-infinite loop for the test. A failure
            // for any other reason (a real bug elsewhere in run()) is
            // left to propagate and fail the test loudly instead of
            // being swallowed here.
            self::assertStringContainsString('HTTP 500', $e->getMessage());
        }

        self::assertNotNull($capturedRequest, 'the handler must have been invoked at least once');
        self::assertSame('/dashboard', $capturedRequest->getUri()->getPath());
        self::assertSame('tab=billing', $capturedRequest->getUri()->getQuery());
        self::assertSame('kinetis_session=abc123; theme=dark', $capturedRequest->getHeaderLine('Cookie'));
        self::assertSame(['kinetis_session' => 'abc123', 'theme' => 'dark'], $capturedRequest->getCookieParams());
        self::assertSame('203.0.113.7', $capturedRequest->getServerParams()['REMOTE_ADDR'] ?? null);

        $responseFile = self::$stateDir . '/response-test-request-1.json';
        self::assertFileExists($responseFile, 'the adapter must have posted the response back to the Runtime API');

        $payload = json_decode((string) file_get_contents($responseFile), associative: true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $payload['statusCode']);
        self::assertSame(['a=1; Path=/', 'b=2; Path=/'], $payload['cookies']);
        self::assertArrayNotHasKey('Set-Cookie', $payload['headers']);
        self::assertTrue($payload['isBase64Encoded']);
        self::assertSame("\xFF\x00binary", base64_decode($payload['body'], strict: true));
    }
}
