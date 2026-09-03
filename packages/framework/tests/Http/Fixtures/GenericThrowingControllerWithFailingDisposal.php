<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Get;
use RuntimeException;

/**
 * The same disposal-failure shape as
 * HttpStatusThrowingControllerWithFailingDisposal, but throwing a plain
 * exception with no declared HTTP status — proves the controller's own
 * real failure is still what ExceptionHandlerMiddleware reports, not the
 * disposal failure that happens afterward.
 */
final readonly class GenericThrowingControllerWithFailingDisposal
{
    public function __construct(
        private RequestScope $scope,
    ) {}

    #[Get('/generic-throws-with-failing-disposal')]
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

        throw new RuntimeException('the controller itself failed');
    }
}
