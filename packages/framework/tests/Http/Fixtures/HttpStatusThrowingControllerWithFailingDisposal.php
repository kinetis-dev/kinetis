<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Get;
use Kinetis\Tests\Fixtures\FixtureHttpStatusException;
use RuntimeException;

/**
 * Registers two onDispose callbacks on its own scope — the first always
 * throws, the second records that it ran — then throws a real, well-
 * behaved HttpStatusExceptionInterface. Proves the declared status
 * survives even when the scope's own disposal also fails afterward, and
 * that RequestScope::dispose()'s own "every callback runs, even after an
 * earlier one throws" guarantee still holds.
 */
final readonly class HttpStatusThrowingControllerWithFailingDisposal
{
    public function __construct(
        private RequestScope $scope,
    ) {}

    #[Get('/http-status-throws-with-failing-disposal')]
    public function boom(): never
    {
        $scope = $this->scope;

        $scope->onDispose(static function (): void {
            throw new RuntimeException('dispose callback failed');
        });
        $scope->onDispose(static function () use ($scope): void {
            DisposalRecorder::$secondRan = true;
            DisposalRecorder::$scope = $scope;
        });

        throw new FixtureHttpStatusException('declared client error');
    }
}
