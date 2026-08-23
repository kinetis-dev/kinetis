<?php

declare(strict_types=1);

namespace Kinetis\Runtime;

use Kinetis\Runtime\Adapters\FpmAdapter;
use Kinetis\Runtime\Adapters\FrankenPhpAdapter;
use Kinetis\Runtime\Exception\RuntimeUnavailableException;

/**
 * Picks the concrete RuntimeAdapterInterface for the environment this
 * process is running in, so a consumer's public/index.php never has to
 * know or care which one applies. Detection signals are accepted as
 * optional parameters (rather than read directly from function_exists()/
 * getenv() inline) purely so tests can exercise every branch without
 * faking global PHP state.
 *
 * The Lambda and RoadRunner branches are class_exists()-gated rather than
 * a direct reference to a concrete adapter, because BrefLambdaAdapter and
 * RoadRunnerAdapter each live in their own separate satellite package, not
 * core — referencing a class name string here is always safe even when
 * that package isn't installed, since PHP resolves a ::class expression to
 * its literal string at compile time without triggering autoloading; only
 * class_exists() or actual instantiation does.
 */
final class RuntimeDetector
{
    private const BREF_ADAPTER_CLASS = 'Kinetis\BrefAdapter\BrefLambdaAdapter';

    private const ROADRUNNER_ADAPTER_CLASS = 'Kinetis\RoadRunnerAdapter\RoadRunnerAdapter';

    public static function detect(
        ?bool $frankenPhpAvailable = null,
        ?string $lambdaRuntimeApi = null,
        ?string $roadRunnerMode = null,
    ): RuntimeAdapterInterface {
        $frankenPhpAvailable ??= function_exists('frankenphp_handle_request');
        $lambdaRuntimeApi ??= getenv('AWS_LAMBDA_RUNTIME_API') ?: null;
        $roadRunnerMode ??= getenv('RR_MODE') ?: null;

        if ($frankenPhpAvailable) {
            return new FrankenPhpAdapter();
        }

        // RR_MODE has several real values (temporal, jobs, grpc, tcp,
        // centrifuge, ...) — this adapter only ever speaks the http one.
        if ($roadRunnerMode === 'http') {
            if (!class_exists(self::ROADRUNNER_ADAPTER_CLASS)) {
                throw RuntimeUnavailableException::missingAdapterPackage('RoadRunnerAdapter', 'kinetis/roadrunner-adapter');
            }

            $adapterClass = self::ROADRUNNER_ADAPTER_CLASS;

            /**
             * @var RuntimeAdapterInterface
             * @psalm-suppress UndefinedClass Deliberate — this class lives
             *     in the separate, optional kinetis/roadrunner-adapter
             *     package, never a dependency of core itself (see this
             *     file's own class-level docblock). The class_exists()
             *     check just above is exactly what makes reaching this
             *     line safe.
             */
            return new $adapterClass();
        }

        if ($lambdaRuntimeApi !== null) {
            if (!class_exists(self::BREF_ADAPTER_CLASS)) {
                throw RuntimeUnavailableException::missingAdapterPackage('BrefLambdaAdapter', 'kinetis/bref-adapter');
            }

            $adapterClass = self::BREF_ADAPTER_CLASS;

            /**
             * @var RuntimeAdapterInterface
             * @psalm-suppress UndefinedClass Deliberate — this class lives
             *     in the separate, optional kinetis/bref-adapter package,
             *     never a dependency of core itself (see this file's own
             *     class-level docblock). The class_exists() check just
             *     above is exactly what makes reaching this line safe.
             */
            return new $adapterClass($lambdaRuntimeApi);
        }

        return new FpmAdapter();
    }
}
