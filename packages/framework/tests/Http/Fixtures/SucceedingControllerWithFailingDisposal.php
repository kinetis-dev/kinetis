<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Get;
use RuntimeException;

/**
 * Succeeds and returns a real response — no response has left the
 * process yet at the point its own scope's disposal then fails, so this
 * proves a successful handler followed by a failed disposal legitimately
 * becomes the ordinary generic 500, logged exactly once.
 */
final readonly class SucceedingControllerWithFailingDisposal
{
    public function __construct(
        private RequestScope $scope,
    ) {}

    #[Get('/succeeds-with-failing-disposal')]
    public function ok(): array
    {
        $scope = $this->scope;

        $scope->onDispose(static function (): void {
            throw new RuntimeException('dispose callback failed');
        });
        $scope->onDispose(static function () use ($scope): void {
            DisposalRecorder::$secondRan = true;
            DisposalRecorder::$scope = $scope;
        });

        return ['status' => 'ok'];
    }
}
