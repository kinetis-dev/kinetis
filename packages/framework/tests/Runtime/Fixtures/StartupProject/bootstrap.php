<?php

declare(strict_types=1);

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\TrustedProxies;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Tests\Instrumentation\RecordingTelemetry;

/**
 * Replaces both runtime policies and installs a telemetry backend — the
 * three things an application bootstrap can only do because startup
 * registers the policies before this chain runs and reports its own
 * phases after it.
 */
return static function (AppScope $app, Config $config): void {
    $app->instance(TrustedProxies::class, TrustedProxies::fromList(['10.0.0.0/8']));
    $app->instance(FormLimits::class, new FormLimits(maxBodyBytes: 4_096));

    $recorder = new RecordingTelemetry();
    Telemetry::global()->swap($recorder);
    $app->instance(RecordingTelemetry::class, $recorder);
};
