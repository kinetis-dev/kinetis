<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Linting;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;

/**
 * Flags the Neuron AI features that block a persistent worker, fork it, or
 * keep state between its requests: a provider, tool or MCP connector built
 * without an HTTP client (Neuron then builds its blocking CurlHttpClient),
 * Neuron's own HTTP clients, parallel tool calls, the file and PDO stores,
 * PhpSerializer, the stdio and SSE MCP transports, and UsageMeter. The
 * reasons and the limitations are documented in
 * {@link https://kinetis.dev/docs/neuron.html}, "What not to use in a
 * worker".
 *
 * A missing client is read from the constructor's signature, so every class
 * with a nullable `NeuronAI\HttpClient\HttpClientInterface` parameter is
 * covered, and `X::make()` of a class using `NeuronAI\StaticConstructor` is
 * judged as `new X`. Names resolve through PHPStan's scope and reflection. A
 * call whose class or method name is an expression, or whose arguments are
 * spread, is not reported.
 *
 * Ships under the main autoload and is loaded only by a PHPStan run, for the
 * reasons `Kinetis\Linting\NoStaticPropertiesRule` gives: an application's
 * own phpstan.neon must reach it, and Composer never installs a
 * dependency's autoload-dev section.
 *
 * @implements Rule<CallLike>
 */
final class NoWorkerUnsafeNeuronRule implements Rule
{
    private const string CLIENT = '%s leaves its $%s argument empty, so Neuron builds a CurlHttpClient whose '
        . 'curl_exec() blocks the worker and its whole event loop for every call. '
        . 'Pass a Kinetis\Neuron\NeuronHttpClient.';

    private const string CURL = '%s waits on curl_exec() and blocks the worker and its whole event loop for every '
        . 'call. Use Kinetis\Neuron\NeuronHttpClient.';

    private const string AMP = '%s changes the instance withBaseUri() and withHeaders() are called on and reads a '
        . 'body with no size limit, so a shared instance keeps one request\'s settings for the next. '
        . 'Use Kinetis\Neuron\NeuronHttpClient.';

    private const string FORK = '%s makes the agent fork the worker process to run its tool calls. '
        . 'Leave parallel tool calls off in a persistent worker.';

    private const string STORAGE = '%s does blocking file or PDO I/O that stops the worker and its whole event '
        . 'loop. Keep the chat history and workflow state in Neuron\'s in-memory defaults, InMemoryMessageStore '
        . 'and InMemoryPersistence, which end with the request.';

    private const string SERIALIZER = '%s unserializes workflow records with no class restriction, so whoever can '
        . 'write the workflow store can run code in the worker. Keep workflow state in Neuron\'s default '
        . 'InMemoryPersistence, which ends with the request.';

    private const string MCP = '%s blocks the worker and its whole event loop: the stdio transport waits on a '
        . 'child process and the SSE transport opens its stream with fopen(). Give McpConnector a url, which '
        . 'uses Streamable HTTP, and a Kinetis\Neuron\NeuronHttpClient.';

    private const string USAGE = '%s records into a static that every request in the worker shares, so it keeps '
        . 'state between requests. Read token usage from each answer\'s Usage, or trace it with '
        . 'Kinetis\Neuron\InferenceTelemetry.';

    private const string CLIENT_INTERFACE = 'NeuronAI\HttpClient\HttpClientInterface';

    private const string STATIC_CONSTRUCTOR = 'NeuronAI\StaticConstructor';

    private const string AGENT = 'NeuronAI\Agent\Agent';

    private const string MCP_CONNECTOR = 'neuronai\mcp\mcpconnector';

    private const string USAGE_METER = 'neuronai\evaluation\usage\usagemeter';

    /** Classes, lowercased, whose construction is always reported. */
    private const array CONSTRUCTIONS = [
        'neuronai\httpclient\curl\curlhttpclient' => self::CURL,
        'neuronai\httpclient\amp\amphttpclient' => self::AMP,
        'neuronai\workflow\persistence\filepersistence' => self::STORAGE,
        'neuronai\workflow\persistence\databasepersistence' => self::STORAGE,
        'neuronai\chat\history\filemessagestore' => self::STORAGE,
        'neuronai\chat\history\sqlmessagestore' => self::STORAGE,
        'neuronai\workflow\persistence\phpserializer' => self::SERIALIZER,
        'neuronai\mcp\stdiotransport' => self::MCP,
        'neuronai\mcp\ssehttptransport' => self::MCP,
        self::USAGE_METER => self::USAGE,
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
        if ($node->isFirstClassCallable()) {
            return [];
        }

        $message = match (true) {
            $node instanceof New_ => $node->class instanceof Name
                ? $this->construction($scope->resolveName($node->class), $node, 'new %s')
                : null,
            $node instanceof StaticCall => $this->staticCall($node, $scope),
            $node instanceof MethodCall => $this->methodCall($node, $scope),
            default => null,
        };

        if ($message === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message($message)
                ->identifier('kinetis.neuronWorkerUnsafe')
                ->build(),
        ];
    }

    private function staticCall(StaticCall $node, Scope $scope): ?string
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
            return null;
        }

        $class = $scope->resolveName($node->class);
        $method = $node->name->toLowerString();

        if ($method === 'watch' && \strtolower($class) === self::USAGE_METER) {
            return \sprintf(self::USAGE, $class . '::watch()');
        }

        if ($method !== 'make' || !$this->reflectionProvider->hasClass($class)) {
            return null;
        }

        $reflection = $this->reflectionProvider->getClass($class);

        return \array_key_exists(self::STATIC_CONSTRUCTOR, $reflection->getTraits(true))
            ? $this->construction($reflection->getName(), $node, '%s::make()')
            : null;
    }

    /**
     * A nullsafe call reaches here too: PHPStan also analyses it as a plain
     * method call on the non-null receiver.
     */
    private function methodCall(MethodCall $node, Scope $scope): ?string
    {
        if (!$node->name instanceof Identifier || $node->name->toLowerString() !== 'paralleltoolcalls') {
            return null;
        }

        if (!new ObjectType(self::AGENT)->isSuperTypeOf($scope->getType($node->var))->yes() || self::spreads($node)) {
            return null;
        }

        $enabled = $node->getArg('enabled', 0);

        if ($enabled !== null && self::isConstant($enabled->value, 'false')) {
            return null;
        }

        return \sprintf(self::FORK, self::AGENT . '::parallelToolCalls()');
    }

    /** $subject is the call as the message names it, with %s for the class. */
    private function construction(string $class, New_|StaticCall $node, string $subject): ?string
    {
        $lowercaseClass = \strtolower($class);
        $subject = \sprintf($subject, $class);
        $template = self::CONSTRUCTIONS[$lowercaseClass] ?? null;

        if ($template !== null) {
            return \sprintf($template, $subject);
        }

        if ($lowercaseClass === self::MCP_CONNECTOR && self::configuresBlockingTransport($node)) {
            return \sprintf(self::MCP, $subject);
        }

        $parameter = $this->omittedClientParameter($class, $node);

        return $parameter === null ? null : \sprintf(self::CLIENT, $subject, $parameter);
    }

    /**
     * The name of the nullable HttpClientInterface constructor parameter the
     * call leaves out or passes a literal null to, if any.
     */
    private function omittedClientParameter(string $class, New_|StaticCall $node): ?string
    {
        if (self::spreads($node) || !$this->reflectionProvider->hasClass($class)) {
            return null;
        }

        $reflection = $this->reflectionProvider->getClass($class);

        if (!$reflection->hasConstructor()) {
            return null;
        }

        $client = new ObjectType(self::CLIENT_INTERFACE);

        foreach ($reflection->getConstructor()->getOnlyVariant()->getParameters() as $position => $parameter) {
            $type = $parameter->getType();

            if (!TypeCombinator::containsNull($type) || !$client->isSuperTypeOf(TypeCombinator::removeNull($type))->yes()) {
                continue;
            }

            $argument = $node->getArg($parameter->getName(), $position);

            return $argument === null || self::isConstant($argument->value, 'null') ? $parameter->getName() : null;
        }

        return null;
    }

    /** A literal config array selecting the stdio (`command`) or SSE (`async: true`) transport. */
    private static function configuresBlockingTransport(New_|StaticCall $node): bool
    {
        if (self::spreads($node)) {
            return false;
        }

        $config = $node->getArg('config', 0)?->value;

        if (!$config instanceof Array_) {
            return false;
        }

        foreach ($config->items as $item) {
            if (!$item->key instanceof String_) {
                continue;
            }

            if ($item->key->value === 'command' || ($item->key->value === 'async' && self::isConstant($item->value, 'true'))) {
                return true;
            }
        }

        return false;
    }

    private static function spreads(CallLike $node): bool
    {
        foreach ($node->getArgs() as $argument) {
            if ($argument->unpack) {
                return true;
            }
        }

        return false;
    }

    private static function isConstant(Expr $value, string $name): bool
    {
        return $value instanceof ConstFetch && $value->name->toLowerString() === $name;
    }
}
