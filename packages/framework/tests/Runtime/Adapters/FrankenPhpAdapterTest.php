<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime\Adapters;

use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\TrustedProxies;
use Kinetis\Runtime\Adapters\FrankenPhpAdapter;
use Kinetis\Runtime\Exception\RuntimeUnavailableException;
use PHPUnit\Framework\TestCase;

final class FrankenPhpAdapterTest extends TestCase
{
    public function test_is_persistent(): void
    {
        self::assertTrue((new FrankenPhpAdapter(new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES), TrustedProxies::fromList([])))->isPersistent());
    }

    public function test_run_throws_when_the_frankenphp_extension_is_unavailable(): void
    {
        // The php:8.4-cli-alpine image this suite runs in has no FrankenPHP
        // extension loaded, so frankenphp_handle_request() never exists
        // here — which is exactly the guard clause this test exercises.
        self::assertFalse(function_exists('frankenphp_handle_request'));

        $this->expectException(RuntimeUnavailableException::class);
        (new FrankenPhpAdapter(new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES), TrustedProxies::fromList([])))->run(static fn () => throw new \LogicException('should never run'));
    }
}
