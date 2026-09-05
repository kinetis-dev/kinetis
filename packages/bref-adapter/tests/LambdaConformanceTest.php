<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter\Tests;

use Kinetis\BrefAdapter\BrefLambdaAdapter;
use Kinetis\BrefAdapter\Tests\Conformance\LambdaDriver;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Testing\Runtime\RuntimeAdapterConformanceTestCase;
use Kinetis\Testing\Runtime\RuntimeAdapterDriver;
use Nyholm\Psr7\Response;

/**
 * The shared runtime conformance suite against this adapter — every
 * behavior SuperglobalsBridge is held to, held here too — plus the one
 * malformed input only a Lambda event can carry, held to the suite's
 * own 400 contract. Behaviors with no cross-adapter meaning at all stay
 * in BrefLambdaAdapterTest.
 */
final class LambdaConformanceTest extends RuntimeAdapterConformanceTestCase
{
    #[\Override]
    protected function driver(): RuntimeAdapterDriver
    {
        return new LambdaDriver();
    }

    /**
     * `isBase64Encoded: true` over a body that isn't base64 has no
     * superglobals counterpart — no SAPI flags a body that way — so the
     * shared suite can't send it. The answer it must get is the shared
     * one regardless: the same 400 a form body the environment can't
     * parse gets, the handler never run, never an invocation error.
     */
    public function test_a_body_flagged_base64_that_is_not_base64_gets_the_same_clean_400(): void
    {
        $handlerRan = false;
        $payload = BrefLambdaAdapter::handleEvent(
            [
                'rawPath' => '/users',
                'requestContext' => [
                    'domainName' => 'kinetis.execute-api.eu-west-1.amazonaws.com',
                    'http' => ['method' => 'POST', 'protocol' => 'HTTP/1.1'],
                ],
                'body' => 'not valid base64 !!! ***',
                'isBase64Encoded' => true,
            ],
            static function () use (&$handlerRan): Response {
                $handlerRan = true;

                return new Response(200);
            },
            new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES),
        );

        self::assertFalse($handlerRan, 'the handler must not run for a body the adapter could not decode');
        self::assertMalformedBodyResponse(LambdaDriver::wireResponseFromPayload($payload));
    }
}
