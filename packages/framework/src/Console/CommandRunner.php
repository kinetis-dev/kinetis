<?php

declare(strict_types=1);

namespace Kinetis\Console;

use Kinetis\Console\Events\CommandFailed;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventDispatcher;
use Kinetis\Logging\SafeLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Runs one command inside its own request scope: creates the scope, binds
 * the Output, resolves the command class through the scope and invokes
 * its method, reports a failure, and disposes the scope. bin/kinetis and
 * TestApplication::command() share it, so a test exercises the same
 * scope, failure, and disposal path production does. The application
 * scope is the caller's to dispose.
 *
 * The method's own return value becomes the exit code: an `int` is used
 * directly, anything else (`void`/`null`) means success (`0`). A thrown
 * command produces `1`; a request-scope disposal failure after an
 * otherwise successful command produces `70`.
 *
 * @internal
 */
final readonly class CommandRunner
{
    public function __construct(
        private AppScope $app,
    ) {}

    /**
     * @param list<string> $arguments
     * @param-out Throwable|null $failure the command's own throwable, if it threw
     */
    public function run(CommandDefinition $command, array $arguments, Output $output, ?Throwable &$failure = null): int
    {
        $failure = null;
        $app = $this->app;
        $commandName = $command->name;

        $scope = $app->createRequestScope();
        $scope->instance(Output::class, $output);

        try {
            // CommandRegistry::register() already validated the method's
            // signature, so this needs no per-call reflection.
            $controller = $scope->get($command->controllerClass);

            $result = $command->takesArguments
                ? $controller->{$command->controllerMethod}(CommandArguments::parse($arguments))
                : $controller->{$command->controllerMethod}();

            $exitCode = is_int($result) ? $result : 0;
        } catch (Throwable $e) {
            $failure = $e;

            // logFrom(), not a direct $app->get(LoggerInterface::class)->error(...)
            // call: PHP would evaluate that get() call before entering any
            // containment at all, so a throwing LoggerInterface binding/factory
            // would escape uncaught right here and take exit(1) down with it —
            // see SafeLogger::logFrom()'s own docblock.
            SafeLogger::logFrom(
                fn (): LoggerInterface => $app->get(LoggerInterface::class),
                LogLevel::ERROR,
                "Command \"{commandName}\" failed: {message}",
                ['commandName' => $commandName, 'message' => $e->getMessage(), 'exception' => $e],
            );
            $exitCode = 1;

            // Secondary reporting: resolving the dispatcher (a package can bind
            // its listener invoker, as kinetis/queue does) or running a listener
            // can throw, and neither may replace exit 1 or skip the disposal
            // below.
            try {
                $scope->get(EventDispatcher::class)->dispatch(new CommandFailed($commandName, $e));
            } catch (Throwable $dispatchFailure) {
                SafeLogger::logFrom(
                    fn (): LoggerInterface => $app->get(LoggerInterface::class),
                    LogLevel::ERROR,
                    "CommandFailed dispatch failed after command \"{commandName}\" failed: {message}",
                    ['commandName' => $commandName, 'message' => $dispatchFailure->getMessage(), 'exception' => $dispatchFailure],
                );
            }
        }

        // The disposal is deliberately outside the try/catch above, not in a
        // `finally`: a Throwable raised in a `finally` block silently replaces
        // whatever exception or return value was already in flight, which
        // would let a cleanup failure here either bypass the command's own
        // intended exit 1 (the catch branch already ran, $exitCode is already
        // decided) or turn a completed, successful command into an uncaught
        // failure outside this runner's error handling entirely. Neither is
        // acceptable, so the disposal is contained in its own try/catch
        // instead, and its outcome never replaces $exitCode's own
        // already-decided value except in the one case below where nothing
        // was decided yet.
        try {
            $scope->dispose();
        } catch (Throwable $disposeFailure) {
            // $scope is already disposed by the time this runs, so the logger
            // is resolved from AppScope (still valid), not the scope itself.
            // logFrom(), not log(): a throwing LoggerInterface binding/factory
            // must be contained too, not just the resolved logger's own log()
            // call — see SafeLogger::logFrom()'s own docblock.
            SafeLogger::logFrom(
                fn (): LoggerInterface => $app->get(LoggerInterface::class),
                LogLevel::ERROR,
                "Request scope disposal failed after command \"{commandName}\" finished: {message}",
                ['commandName' => $commandName, 'message' => $disposeFailure->getMessage(), 'exception' => $disposeFailure],
            );

            if ($exitCode === 0) {
                // The command itself completed successfully — no exception, no
                // deliberate non-zero return — so there is nothing else already
                // signaling a problem. A cleanup failure means the unit of work
                // did not finish cleanly, though, so this cannot report a plain
                // 0 either. 70 is EX_SOFTWARE from BSD sysexits.h ("an internal
                // software error has been detected"), the deterministic,
                // documented non-zero code chosen for exactly this case, kept
                // distinct from the 1 a genuine command failure above already
                // produces and from any exit code a command's own logic
                // returns deliberately.
                $exitCode = 70;
            }
        }

        return $exitCode;
    }
}
