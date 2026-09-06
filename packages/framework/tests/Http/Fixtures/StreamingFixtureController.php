<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\StreamedResponse;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Streamed responses whose emitters resolve from the request's own
 * scope, in the three shapes the deferred-disposal contract has to
 * separate: emission succeeding, disposal failing under a successful
 * emission, and both failing at once.
 */
#[Middleware(StreamTagMiddleware::class)]
final readonly class StreamingFixtureController
{
    public function __construct(
        private RequestScope $scope,
    ) {}

    #[Get('/stream')]
    public function stream(): ResponseInterface
    {
        return $this->streamed(emitterFails: false, disposalFails: false);
    }

    #[Get('/stream/failing-disposal')]
    public function failingDisposal(): ResponseInterface
    {
        return $this->streamed(emitterFails: false, disposalFails: true);
    }

    #[Get('/stream/failing-emitter')]
    public function failingEmitter(): ResponseInterface
    {
        return $this->streamed(emitterFails: true, disposalFails: true);
    }

    private function streamed(bool $emitterFails, bool $disposalFails): ResponseInterface
    {
        $scope = $this->scope;
        $tag = $scope->get(StreamTagInterface::class)->value;

        StreamProbe::$scopes[] = $scope;
        StreamProbe::$events[] = 'dispatch:' . $tag;

        $scope->onDispose(static function () use ($disposalFails): void {
            StreamProbe::$events[] = 'disposed';
            StreamProbe::$collectionsAtDisposal = StreamProbe::collections();

            if ($disposalFails) {
                throw new RuntimeException('dispose callback failed');
            }
        });

        $inner = new Response(200, ['Content-Type' => 'text/event-stream'], null, '1.1', 'Streaming');

        return new StreamedResponse($inner, static function () use ($scope, $emitterFails): void {
            StreamProbe::$events[] = 'emitted:' . $scope->get(StreamTagInterface::class)->value;

            if ($emitterFails) {
                throw new RuntimeException('the emitter itself failed');
            }
        });
    }
}
