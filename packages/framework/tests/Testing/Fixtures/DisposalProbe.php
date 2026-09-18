<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing\Fixtures;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Testing\ApplicationTestCase;
use RuntimeException;

/**
 * An ApplicationTestCase whose boot- and dispose-hooks are driven
 * directly, so both can be observed from one ordinary test: PHPUnit's
 * own per-test lifecycle cannot report, from inside a test, what
 * happened after it.
 *
 * With $failBoot set, the before-hook throws where a real suite's test
 * double registration would — leaving the typed $application property
 * never assigned, which is the state PHPUnit then runs the after-hook
 * in.
 */
final class DisposalProbe extends ApplicationTestCase
{
    public int $disposals = 0;

    public bool $failBoot = false;

    #[\Override]
    protected function projectRoot(): string
    {
        return dirname(__DIR__, 2) . '/Cache/Fixtures';
    }

    #[\Override]
    protected function configOverrides(): array
    {
        return ['APP_ENV' => 'development'];
    }

    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        if ($this->failBoot) {
            throw new RuntimeException('test double registration failed');
        }

        $app->onDispose(function (): void {
            ++$this->disposals;
        });
    }

    public function boot(): void
    {
        $this->bootApplication();
    }

    public function disposeAfterTest(): void
    {
        $this->disposeApplication();
    }
}
