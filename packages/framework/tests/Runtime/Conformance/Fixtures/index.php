<?php

declare(strict_types=1);

// The front controller the conformance suite runs the superglobals
// adapters through — under `php -S` (spawned by SuperglobalsDriver), a
// real FrankenPHP worker, or PHP-FPM behind nginx (the integration job).
// RuntimeDetector picks the adapter the way public/index.php does, so
// each environment runs its real adapter's run() loop: FrankenPhpAdapter
// inside frankenphp_handle_request(), FpmAdapter elsewhere. Named
// index.php because FrankenPHP's php-server routes a worker through the
// document root's index.php and nothing else.
//
// The handler records what it received and answers with whatever the
// driver asked for.
//
// The response must leave the wire untouched — its status, headers,
// cookies and bytes are what the suite asserts on — so the observed
// request cannot ride in the response. It goes to a file instead, named
// by the per-dispatch id the driver sends, under the directory the
// driver passed in through the environment (the same shape as
// kinetis/bref-adapter's fake Runtime API fixture).
//
// A parse failure inside the adapter never reaches this
// handler at all — that is the point of the conformance case that
// exercises it — so a missing observed-request file is a meaningful
// outcome the driver reports as "the handler never ran", not an error.

require __DIR__ . '/../../../../vendor/autoload.php';

use Kinetis\Config\Config;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\TrustedProxies;
use Kinetis\Runtime\RuntimeDetector;
use Kinetis\Testing\Runtime\ObservedRequest;
use Kinetis\Testing\Runtime\ResponseSpec;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The policies an application builds at its own entry point. Both come
// from this process's environment, so a driver decides them the same way
// a deployment does: MAX_BODY_SIZE for the byte ceiling, TRUSTED_PROXIES
// for whose forwarded headers are believed.
$config = Config::fromEnvironment();

RuntimeDetector::detect(FormLimits::fromConfig($config), TrustedProxies::fromConfig($config))
    ->run(static function (ServerRequestInterface $request): ResponseInterface {
        // Readiness, through the whole path — RuntimeDetector, the adapter's
        // run() loop, the bridge, this handler — rather than a TCP accept,
        // which a proxy answers before the SAPI behind it is up. The driver
        // and the integration job both poll this before the first dispatch.
        if ($request->getUri()->getPath() === '/__conformance/ready') {
            return new \Nyholm\Psr7\Response(204);
        }

        $stateDir = getenv('KINETIS_CONFORMANCE_STATE_DIR');
        $id = $request->getHeaderLine('X-Conformance-Id');

        if (!is_string($stateDir) || $stateDir === '' || preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
            throw new LogicException('conformance front controller invoked without its state directory or dispatch id');
        }

        file_put_contents(
            $stateDir . '/' . $id . '.json',
            json_encode(ObservedRequest::fromServerRequest($request)->toArray(), JSON_THROW_ON_ERROR),
        );

        /** @var array<string, mixed> $spec */
        $spec = json_decode(
            (string) base64_decode($request->getHeaderLine('X-Conformance-Response'), strict: true),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return ResponseSpec::fromArray($spec)->toResponse();
    });
