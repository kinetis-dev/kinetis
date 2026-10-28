<?php

declare(strict_types=1);

namespace Kinetis\Tests\Linting;

use Kinetis\Linting\NoProcessGlobalsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Each analysis runs in its own process, as NoBlockingIoRuleTest's do:
 * a cold-cache analysis in the suite's shared process can exceed PHP's
 * default memory limit, and the negative case defines App\header() for
 * the rest of its process.
 *
 * @extends RuleTestCase<NoProcessGlobalsRule>
 */
#[RunTestsInSeparateProcesses]
final class NoProcessGlobalsRuleTest extends RuleTestCase
{
    private const string FIXTURE = __DIR__ . '/Fixtures/ProcessGlobalsCalls.php';

    private const string EXIT = '%s ends the PHP process before the response is sent and the request scope is '
        . 'disposed, and in a persistent worker it ends every in-flight request on its event loop. Return a '
        . 'response or throw from a handler; a console command returns its exit code.';

    private const string SUPERGLOBAL = '%s bypasses the PSR-7 request: RoadRunner and Lambda never fill it, and '
        . 'under PHP-FPM and FrankenPHP the required enable_post_data_reading=0 leaves $_POST and $_FILES empty. '
        . 'Use %s.';

    private const string HEADERS = '%s writes to the SAPI rather than the response: RoadRunner and Lambda run '
        . 'on the CLI SAPI, which discards it, and under PHP-FPM and FrankenPHP it bypasses the response '
        . 'Kinetis emits. Set the status and headers on the returned ResponseInterface.';

    private const string COOKIES = '%s writes to the SAPI rather than the response: RoadRunner and Lambda run '
        . 'on the CLI SAPI, which discards it, and under PHP-FPM and FrankenPHP it bypasses the response '
        . 'Kinetis emits. Add a Set-Cookie header to the returned ResponseInterface.';

    private const string SESSION = '%s drives PHP\'s native session, which reads and writes SAPI cookies and '
        . 'process-wide state rather than the request and response. Use Kinetis\Session\Session from '
        . 'kinetis/session.';

    protected function getRule(): Rule
    {
        return new NoProcessGlobalsRule(self::createReflectionProvider());
    }

    public function test_flags_every_superglobal_sapi_call_and_exit(): void
    {
        $this->analyse([self::FIXTURE], [
            [sprintf(self::SUPERGLOBAL, '$_GET', 'ServerRequestInterface::getQueryParams()'), 11],
            [sprintf(self::SUPERGLOBAL, '$_POST', 'ServerRequestInterface::getParsedBody()'), 12],
            [sprintf(self::SUPERGLOBAL, '$_COOKIE', 'ServerRequestInterface::getCookieParams()'), 13],
            [sprintf(self::SUPERGLOBAL, '$_FILES', 'ServerRequestInterface::getUploadedFiles()'), 14],
            [sprintf(self::SUPERGLOBAL, '$_REQUEST', 'ServerRequestInterface::getQueryParams() or getParsedBody()'), 15],
            [sprintf(self::SUPERGLOBAL, '$_SESSION', 'Kinetis\Session\Session from kinetis/session'), 16],
            [
                sprintf(
                    self::SUPERGLOBAL,
                    '$_SERVER',
                    'ServerRequestInterface::getServerParams() or a request header for request data, '
                    . 'or getenv() for the environment',
                ),
                17,
            ],
            [sprintf(self::SUPERGLOBAL, '$_GET', 'ServerRequestInterface::getQueryParams()'), 18],
            [sprintf(self::HEADERS, 'header()'), 23],
            [sprintf(self::HEADERS, 'header()'), 24],
            [sprintf(self::HEADERS, 'header_remove()'), 25],
            [sprintf(self::HEADERS, 'http_response_code()'), 26],
            [sprintf(self::COOKIES, 'setcookie()'), 27],
            [sprintf(self::COOKIES, 'setrawcookie()'), 28],
            [sprintf(self::SESSION, 'session_start()'), 29],
            [sprintf(self::SESSION, 'session_regenerate_id()'), 30],
            [sprintf(self::EXIT, 'exit'), 35],
            [sprintf(self::EXIT, 'exit'), 40],
            [sprintf(self::EXIT, 'die'), 45],
            [sprintf(self::EXIT, 'exit'), 50],
        ]);
    }

    public function test_reports_each_kind_under_its_own_identifier(): void
    {
        $identifiers = [];

        foreach ($this->gatherAnalyserErrors([self::FIXTURE]) as $error) {
            $identifiers[$error->getLine()][] = $error->getIdentifier();
        }

        self::assertSame([
            11 => ['kinetis.superglobal'],
            12 => ['kinetis.superglobal'],
            13 => ['kinetis.superglobal'],
            14 => ['kinetis.superglobal'],
            15 => ['kinetis.superglobal'],
            16 => ['kinetis.superglobal'],
            17 => ['kinetis.superglobal'],
            18 => ['kinetis.superglobal'],
            23 => ['kinetis.sapiCall'],
            24 => ['kinetis.sapiCall'],
            25 => ['kinetis.sapiCall'],
            26 => ['kinetis.sapiCall'],
            27 => ['kinetis.sapiCall'],
            28 => ['kinetis.sapiCall'],
            29 => ['kinetis.sapiCall'],
            30 => ['kinetis.sapiCall'],
            35 => ['kinetis.exit'],
            40 => ['kinetis.exit'],
            45 => ['kinetis.exit'],
            50 => ['kinetis.exit'],
        ], $identifiers);
    }

    public function test_does_not_flag_namespaced_functions_members_dynamic_names_or_other_globals(): void
    {
        // The test container reflects functions PHP has loaded, not ones
        // declared in an analysed file, so App\header() must exist here
        // for name resolution to find it the way a project analysis does.
        require_once __DIR__ . '/Fixtures/ProcessGlobalsFree.php';

        $this->analyse([__DIR__ . '/Fixtures/ProcessGlobalsFree.php'], []);
    }
}
