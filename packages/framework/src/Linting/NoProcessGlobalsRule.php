<?php

declare(strict_types=1);

namespace Kinetis\Linting;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags application code that reaches past the PSR-7 request and response
 * into the PHP process or SAPI: `exit` and `die`, the request
 * superglobals, and the SAPI header, cookie, and session functions. In a
 * persistent worker `exit` ends every in-flight request on the worker's
 * loop. Under RoadRunner and Lambda the superglobals are not the request
 * and a SAPI header never reaches the client; under PHP-FPM and
 * FrankenPHP both bypass the request and response Kinetis builds. The
 * kinds, their replacements, and how to exempt a site are documented in
 * docs/runtime-adapters.md, "Process and SAPI globals".
 *
 * Function names resolve the way PHP resolves them, through PHPStan's
 * scope and reflection: an unqualified `header()` inside a namespace that
 * declares its own `header()` calls that function and is not reported.
 * `exit` and `die` are reserved words, so an unqualified or fully
 * qualified call by either name is always the language's own. A variable
 * or function whose name is an expression, and a property, method, or
 * class constant that merely shares a reported name, are not reported.
 *
 * Ships under the main autoload and is loaded only by a PHPStan run, for
 * the reasons {@see NoStaticPropertiesRule} gives.
 *
 * @implements Rule<Expr>
 */
final class NoProcessGlobalsRule implements Rule
{
    private const string EXIT = '%s ends the PHP process before the response is sent and the request scope is '
        . 'disposed, and in a persistent worker it ends every in-flight request on its event loop. Return a '
        . 'response or throw from a handler; a console command returns its exit code.';

    private const string SUPERGLOBAL = '%s bypasses the PSR-7 request: RoadRunner and Lambda never fill it, and '
        . 'under PHP-FPM and FrankenPHP the required enable_post_data_reading=0 leaves $_POST and $_FILES empty. '
        . 'Use %s.';

    private const string HEADERS = '%s writes to the SAPI rather than the response: RoadRunner redirects it to '
        . 'STDERR, Lambda\'s CLI SAPI discards it, and under PHP-FPM and FrankenPHP it bypasses the response '
        . 'Kinetis emits. Set the status and headers on the returned ResponseInterface.';

    private const string COOKIES = '%s writes to the SAPI rather than the response: RoadRunner redirects it to '
        . 'STDERR, Lambda\'s CLI SAPI discards it, and under PHP-FPM and FrankenPHP it bypasses the response '
        . 'Kinetis emits. Add a Set-Cookie header to the returned ResponseInterface.';

    private const string SESSION = '%s drives PHP\'s native session, which reads and writes SAPI cookies and '
        . 'process-wide state rather than the request and response. Use Kinetis\Session\Session from '
        . 'kinetis/session.';

    /** Superglobal names, without the `$`, to their replacement. */
    private const array SUPERGLOBALS = [
        '_GET' => 'ServerRequestInterface::getQueryParams()',
        '_POST' => 'ServerRequestInterface::getParsedBody()',
        '_COOKIE' => 'ServerRequestInterface::getCookieParams()',
        '_FILES' => 'ServerRequestInterface::getUploadedFiles()',
        '_REQUEST' => 'ServerRequestInterface::getQueryParams() or getParsedBody()',
        '_SESSION' => 'Kinetis\Session\Session from kinetis/session',
        '_SERVER' => 'ServerRequestInterface::getServerParams() or a request header for request data, '
            . 'or getenv() for the environment',
    ];

    /**
     * Global functions, lowercased, to the message template for their
     * category. Every other global `session_*` function takes SESSION.
     */
    private const array FUNCTIONS = [
        'header' => self::HEADERS,
        'header_remove' => self::HEADERS,
        'http_response_code' => self::HEADERS,
        'setcookie' => self::COOKIES,
        'setrawcookie' => self::COOKIES,
    ];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    #[\Override]
    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @param Expr $node
     * @return list<IdentifierRuleError>
     */
    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $error = match (true) {
            $node instanceof Exit_ => self::exitCall($node->getAttribute('kind') === Exit_::KIND_DIE ? 'die' : 'exit'),
            $node instanceof Variable => self::superglobal($node),
            $node instanceof FuncCall => $this->functionCall($node, $scope),
            default => null,
        };

        return $error === null ? [] : [$error];
    }

    private function functionCall(FuncCall $node, Scope $scope): ?IdentifierRuleError
    {
        if (!$node->name instanceof Name) {
            return null;
        }

        // php-parser builds a FuncCall rather than an Exit_ for `\exit()`
        // and for `exit` with a named, spread, or first-class-callable
        // argument.
        $keyword = $node->name->toLowerString();

        if ($keyword === 'exit' || $keyword === 'die') {
            return self::exitCall($keyword);
        }

        $function = $this->reflectionProvider->resolveFunctionName($node->name, $scope);

        if ($function === null) {
            return null;
        }

        $lowercaseFunction = \strtolower($function);
        $template = self::FUNCTIONS[$lowercaseFunction]
            ?? (\str_starts_with($lowercaseFunction, 'session_') ? self::SESSION : null);

        if ($template === null) {
            return null;
        }

        return RuleErrorBuilder::message(\sprintf($template, $function . '()'))
            ->identifier('kinetis.sapiCall')
            ->build();
    }

    private static function exitCall(string $keyword): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf(self::EXIT, $keyword))
            ->identifier('kinetis.exit')
            ->build();
    }

    private static function superglobal(Variable $node): ?IdentifierRuleError
    {
        if (!\is_string($node->name) || !isset(self::SUPERGLOBALS[$node->name])) {
            return null;
        }

        $message = \sprintf(self::SUPERGLOBAL, '$' . $node->name, self::SUPERGLOBALS[$node->name]);

        return RuleErrorBuilder::message($message)
            ->identifier('kinetis.superglobal')
            ->build();
    }
}
