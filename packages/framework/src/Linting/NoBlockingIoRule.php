<?php

declare(strict_types=1);

namespace Kinetis\Linting;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags calls that wait synchronously on I/O, a timer, or a child process,
 * and HTTP client construction that picks a transport without guaranteeing
 * a Revolt-backed one. Running such a call inside a Fiber does not make it
 * yield: it holds the worker and every other Fiber and watcher on its event
 * loop until it returns. The categories, their replacements, and the
 * limitations are documented in docs/concurrency.md, "Keeping application
 * I/O non-blocking".
 *
 * Names resolve the way PHP resolves them, through PHPStan's scope and
 * reflection: an unqualified `sleep()` inside a namespace that declares its
 * own `sleep()` calls that function and is not reported. A call whose
 * function or class name is an expression is not reported.
 *
 * Ships under the main autoload and is loaded only by a PHPStan run, for
 * the reasons {@see NoStaticPropertiesRule} gives.
 *
 * @implements Rule<CallLike>
 */
final class NoBlockingIoRule implements Rule
{
    private const string SLEEP = '%s blocks the worker and its whole event loop until it returns. '
        . 'Use Kinetis\Async\Timer::delay(), which suspends only the calling Fiber.';

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

    /** Global functions, lowercased, to the message template for their category. */
    private const array FUNCTIONS = [
        'sleep' => self::SLEEP,
        'usleep' => self::SLEEP,
        'time_nanosleep' => self::SLEEP,
        'time_sleep_until' => self::SLEEP,
        'fsockopen' => self::SOCKET,
        'pfsockopen' => self::SOCKET,
        'stream_socket_client' => self::SOCKET,
        'curl_exec' => self::CURL,
        'curl_multi_select' => self::CURL,
        'mysqli_connect' => self::DATABASE,
        'pg_connect' => self::DATABASE,
        'pg_pconnect' => self::DATABASE,
        'exec' => self::PROCESS,
        'shell_exec' => self::PROCESS,
        'system' => self::PROCESS,
        'passthru' => self::PROCESS,
        'proc_open' => self::PROCESS,
        'popen' => self::PROCESS,
    ];

    /** Classes, lowercased, whose construction is always reported. */
    private const array CONSTRUCTIONS = [
        'pdo' => self::DATABASE,
        'mysqli' => self::DATABASE,
        'guzzlehttp\client' => self::HTTP,
    ];

    /**
     * PSR-18 clients, lowercased, that pick their own transport when their
     * `client` argument is absent or null: Psr18ClientDiscovery::find() and
     * HttpClient::create() respectively.
     */
    private const array CLIENT_DEFAULTING_CONSTRUCTIONS = [
        'http\discovery\psr18client',
        'symfony\component\httpclient\httplugclient',
        'symfony\component\httpclient\psr18client',
    ];

    /** Classes, lowercased, to their reported static methods, lowercased. */
    private const array HTTP_STATIC_CALLS = [
        'http\discovery\psr18clientdiscovery' => ['find'],
        'http\discovery\httpclientdiscovery' => ['find'],
        'http\discovery\httpasyncclientdiscovery' => ['find'],
        'symfony\component\httpclient\httpclient' => ['create', 'createforbaseuri'],
    ];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    #[\Override]
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @param CallLike $node
     * @return list<IdentifierRuleError>
     */
    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $message = match (true) {
            $node instanceof FuncCall => $this->functionCall($node, $scope),
            $node instanceof StaticCall => $this->staticCall($node, $scope),
            $node instanceof New_ => $this->construction($node, $scope),
            default => null,
        };

        if ($message === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message($message)
                ->identifier('kinetis.blockingCall')
                ->build(),
        ];
    }

    private function functionCall(FuncCall $node, Scope $scope): ?string
    {
        if (!$node->name instanceof Name) {
            return null;
        }

        $function = $this->reflectionProvider->resolveFunctionName($node->name, $scope);
        $template = $function === null ? null : (self::FUNCTIONS[\strtolower($function)] ?? null);

        return $template === null ? null : \sprintf($template, $function . '()');
    }

    private function staticCall(StaticCall $node, Scope $scope): ?string
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
            return null;
        }

        $class = $scope->resolveName($node->class);
        $methods = self::HTTP_STATIC_CALLS[\strtolower($class)] ?? [];

        if (!\in_array($node->name->toLowerString(), $methods, true)) {
            return null;
        }

        return \sprintf(self::HTTP, $class . '::' . $node->name->toString() . '()');
    }

    private function construction(New_ $node, Scope $scope): ?string
    {
        if (!$node->class instanceof Name) {
            return null;
        }

        $class = $scope->resolveName($node->class);
        $lowercaseClass = \strtolower($class);
        $template = self::CONSTRUCTIONS[$lowercaseClass] ?? null;

        if (\in_array($lowercaseClass, self::CLIENT_DEFAULTING_CONSTRUCTIONS, true) && self::omitsClient($node)) {
            $template = self::HTTP;
        }

        return $template === null ? null : \sprintf($template, 'new ' . $class);
    }

    private static function omitsClient(New_ $node): bool
    {
        $client = $node->getArg('client', 0);

        return $client === null
            || ($client->value instanceof ConstFetch && $client->value->name->toLowerString() === 'null');
    }
}
