<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Container\AppScope;
use Kinetis\Http\Dispatcher;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Fixtures\UnreadableUploadedFile;
use Kinetis\Tests\Http\Fixtures\UploadBindingController;
use Kinetis\Tests\Http\Fixtures\UploadController;
use Kinetis\Validation\Exception\ValidationException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * What a route binds from a request's uploaded files.
 *
 * Two facts drive every case here. A browser submits an *empty* file
 * control as a present UPLOAD_ERR_NO_FILE part whose stream throws, so
 * "the user picked no file" has to become ordinary omission before any
 * plan sees it. And a multipart form parses into two trees — text
 * values and files — that a DTO declares as one set of fields, so the
 * two have to be merged at every depth the form nests.
 */
final class UploadBindingTest extends TestCase
{
    private const array FORM_HEADERS = ['Content-Type' => 'multipart/form-data; boundary=----WebKitFormBoundary'];

    private function dispatcher(): Dispatcher
    {
        $app = new AppScope();
        $app->boot();

        return new Dispatcher($app);
    }

    private function router(): Router
    {
        $router = new Router();
        $router->register(UploadBindingController::class);

        return $router;
    }

    /**
     * @param array<string, mixed> $parsedBody
     * @param array<string, mixed> $files
     */
    private function formRequest(string $path, array $parsedBody, array $files): ServerRequestInterface
    {
        return (new ServerRequest('POST', $path, self::FORM_HEADERS))
            ->withParsedBody($parsedBody)
            ->withUploadedFiles($files);
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchJson(string $path, ServerRequestInterface $request): array
    {
        $response = $this->dispatcher()->dispatch($this->router()->match('POST', $path), $request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true);

        return $decoded;
    }

    private function failure(string $path, ServerRequestInterface $request): ValidationException
    {
        try {
            $this->dispatcher()->dispatch($this->router()->match('POST', $path), $request);
        } catch (ValidationException $e) {
            return $e;
        }

        self::fail('Expected the dispatch to fail validation.');
    }

    private static function png(string $name = 'photo.png'): UploadedFileInterface
    {
        return new UploadedFile(Stream::create('bytes'), 5, UPLOAD_ERR_OK, $name, 'image/png');
    }

    private static function emptyControl(): UploadedFileInterface
    {
        return new UploadedFile(Stream::create(''), 0, UPLOAD_ERR_NO_FILE, '', '');
    }

    // --- An empty file control is omission, not a present file. ---

    public function test_a_required_field_reports_an_empty_control_as_a_missing_one(): void
    {
        $router = new Router();
        $router->register(UploadController::class);
        $request = $this->formRequest('/avatars', ['name' => 'Alon'], ['avatar' => self::emptyControl()]);

        try {
            $this->dispatcher()->dispatch($router->match('POST', '/avatars'), $request);
            self::fail('Expected the dispatch to fail validation.');
        } catch (ValidationException $e) {
            self::assertSame(['avatar' => ['is required.']], $e->grouped());
        }
    }

    public function test_an_empty_control_leaves_a_defaulted_field_at_its_default_and_a_presence_union_absent(): void
    {
        $request = $this->formRequest('/avatars/presence', [], [
            'avatar' => self::emptyControl(),
            'thumbnail' => self::emptyControl(),
        ]);

        self::assertSame(['avatar' => null, 'thumbnail' => 'absent'], $this->dispatchJson('/avatars/presence', $request));
    }

    public function test_an_empty_control_leaves_a_nullable_parameter_null(): void
    {
        $request = $this->formRequest('/thumbnails', [], ['file' => self::emptyControl()]);

        self::assertSame(['filename' => null], $this->dispatchJson('/thumbnails', $request));
    }

    public function test_an_empty_control_makes_a_required_parameter_report_as_missing(): void
    {
        $request = $this->formRequest('/scans', [], ['file' => self::emptyControl()]);

        self::assertSame(['file' => ['is required.']], $this->failure('/scans', $request)->grouped());
    }

    /**
     * Normalization is what *binding* reads. The PSR-7 request keeps the
     * bag its runtime adapter built, so a middleware or a
     * ServerRequestInterface-typed parameter still sees the part the
     * client actually sent.
     */
    public function test_normalization_does_not_touch_the_requests_own_uploaded_files(): void
    {
        $control = self::emptyControl();
        $request = $this->formRequest('/thumbnails', [], ['file' => $control]);

        $this->dispatchJson('/thumbnails', $request);

        $bag = $request->getUploadedFiles();
        self::assertSame($control, $bag['file']);
        self::assertSame(UPLOAD_ERR_NO_FILE, $bag['file']->getError());
    }

    // --- Repeated file controls. ---

    public function test_a_repeated_control_binds_the_files_that_arrived_reindexed_from_zero(): void
    {
        $request = $this->formRequest('/photos', [], [
            'photos' => [self::png('first.png'), self::emptyControl(), self::png('second.png')],
        ]);

        self::assertSame(['photos' => ['first.png', 'second.png']], $this->dispatchJson('/photos', $request));
    }

    /**
     * A pruned branch answers "not supplied", never `[]`. An empty list
     * is a list the client sent, which a required field would bind and a
     * count rule would then measure.
     */
    public function test_a_repeated_control_left_wholly_empty_is_an_omitted_field(): void
    {
        $request = $this->formRequest('/photos', [], ['photos' => [self::emptyControl()]]);

        self::assertSame(['photos' => ['is required.']], $this->failure('/photos', $request)->grouped());
    }

    // --- Nested trees. ---

    public function test_a_nested_text_field_and_a_nested_file_field_hydrate_the_same_dto(): void
    {
        $request = $this->formRequest(
            '/profiles',
            ['profile' => ['name' => 'Alon']],
            ['profile' => ['avatar' => self::png('avatar.png')]],
        );

        self::assertSame(
            ['name' => 'Alon', 'avatar' => 'avatar.png'],
            $this->dispatchJson('/profiles', $request),
        );
    }

    /**
     * A form naming one key as both a text value and a file has
     * contradicted itself. The text stays, so the field reports the
     * ordinary declared-type violation any other wrong value would get,
     * rather than a silently chosen winner.
     */
    public function test_a_text_value_colliding_with_a_file_keeps_the_text_and_reports_the_type(): void
    {
        $request = $this->formRequest(
            '/profiles',
            ['profile' => ['name' => 'Alon', 'avatar' => 'x']],
            ['profile' => ['avatar' => self::png('avatar.png')]],
        );

        $violations = $this->failure('/profiles', $request)->violations;

        self::assertCount(1, $violations);
        self::assertSame(['profile', 'avatar'], $violations[0]->path);
        self::assertSame('not_an_instance', $violations[0]->code);
    }

    public function test_a_json_body_does_not_consume_the_requests_uploaded_files(): void
    {
        $request = (new ServerRequest('POST', '/profiles', ['Content-Type' => 'application/json'], body: '{"profile":{"name":"Alon"}}'))
            ->withUploadedFiles(['profile' => ['avatar' => self::png('avatar.png')]]);

        self::assertSame(
            ['profile.avatar' => ['is required.']],
            $this->failure('/profiles', $request)->grouped(),
        );
    }

    // --- A file that did not arrive intact is one violation, and
    // nothing else is asked of it. ---

    public function test_a_failed_status_in_a_list_reports_only_that_index_and_consults_no_rule(): void
    {
        $request = $this->formRequest('/photos', [], [
            'photos' => [
                self::png('first.png'),
                new UnreadableUploadedFile(UPLOAD_ERR_PARTIAL, null, 'x.txt'),
                self::png('second.png'),
            ],
        ]);

        $violations = $this->failure('/photos', $request)->violations;

        self::assertCount(1, $violations);
        self::assertSame(['photos', 1], $violations[0]->path);
        self::assertSame('upload_failed', $violations[0]->code);
        self::assertSame('could not be uploaded.', $violations[0]->message);
        self::assertSame(['error' => UPLOAD_ERR_PARTIAL], $violations[0]->parameters);
    }

    public function test_a_failed_status_on_a_nested_field_never_reaches_the_controller(): void
    {
        $request = $this->formRequest(
            '/profiles',
            ['profile' => ['name' => 'Alon']],
            ['profile' => ['avatar' => new UnreadableUploadedFile(UPLOAD_ERR_CANT_WRITE, null, 'avatar.png')]],
        );

        $violations = $this->failure('/profiles', $request)->violations;

        self::assertCount(1, $violations);
        self::assertSame(['profile', 'avatar'], $violations[0]->path);
        self::assertSame('upload_failed', $violations[0]->code);
        self::assertSame(['error' => UPLOAD_ERR_CANT_WRITE], $violations[0]->parameters);
    }

    public function test_a_failed_status_on_a_direct_parameter_never_reaches_the_controller(): void
    {
        $request = $this->formRequest('/scans', [], [
            'file' => new UnreadableUploadedFile(UPLOAD_ERR_NO_TMP_DIR, null, 'scan.png'),
        ]);

        $violations = $this->failure('/scans', $request)->violations;

        self::assertCount(1, $violations);
        self::assertSame(['file'], $violations[0]->path);
        self::assertSame('upload_failed', $violations[0]->code);
    }

    // --- A direct parameter's own rules run. ---

    public function test_a_direct_parameters_own_rule_runs_against_the_file_it_bound(): void
    {
        $request = $this->formRequest('/scans', [], [
            'file' => new UploadedFile(Stream::create('bytes'), 5, UPLOAD_ERR_OK, 'x.txt', 'text/plain'),
        ]);

        $violations = $this->failure('/scans', $request)->violations;

        self::assertCount(1, $violations);
        self::assertSame(['file'], $violations[0]->path);
        self::assertSame('file_extension', $violations[0]->code);
        self::assertSame(['choices' => ['png']], $violations[0]->parameters);
    }

    public function test_a_direct_parameter_binds_a_file_its_rule_accepts(): void
    {
        $request = $this->formRequest('/scans', [], ['file' => self::png('scan.PNG')]);

        self::assertSame(['filename' => 'scan.PNG'], $this->dispatchJson('/scans', $request));
    }

    public function test_a_directory_shaped_branch_is_not_a_file_for_a_direct_parameter(): void
    {
        $request = $this->formRequest('/scans', [], ['file' => ['nested' => self::png()]]);

        $violations = $this->failure('/scans', $request)->violations;

        self::assertCount(1, $violations);
        self::assertSame(['file'], $violations[0]->path);
        self::assertSame('not_an_instance', $violations[0]->code);
    }

    /**
     * An element list a rule accepts binds every file, so the failing
     * cases above are failing for their own reason and not because an
     * upload list never binds at all.
     */
    public function test_an_element_rule_runs_against_every_file_that_arrived(): void
    {
        $accepted = $this->formRequest('/photos', [], ['photos' => [self::png('a.png'), self::png('b.png')]]);
        self::assertSame(['photos' => ['a.png', 'b.png']], $this->dispatchJson('/photos', $accepted));

        $refused = $this->formRequest('/photos', [], ['photos' => [self::png('a.png'), self::png('b.gif')]]);
        $violations = $this->failure('/photos', $refused)->violations;

        self::assertCount(1, $violations);
        self::assertSame(['photos', 1], $violations[0]->path);
        self::assertSame('file_extension', $violations[0]->code);
    }

    public function test_a_list_element_dto_binds_its_own_upload_field(): void
    {
        $request = $this->formRequest(
            '/galleries',
            ['entries' => [['caption' => 'One'], ['caption' => 'Two']]],
            ['entries' => [['image' => self::png('one.png')], ['image' => self::png('two.png')]]],
        );

        self::assertSame(
            ['entries' => [
                ['caption' => 'One', 'image' => 'one.png'],
                ['caption' => 'Two', 'image' => 'two.png'],
            ]],
            $this->dispatchJson('/galleries', $request),
        );
    }

    /**
     * Closing a pruned list's indices is right for `photos[]`, where an
     * index is nothing but position among files, and wrong for a list of
     * DTOs, where the same index is the position the parsed text names.
     * The middle entry's control is empty and the third entry's file
     * arrived: reindexing the outer branch would hand the third file to
     * the second caption, and an optional field would hydrate that
     * silently.
     */
    public function test_an_empty_control_in_a_list_of_dtos_leaves_every_later_file_on_its_own_entry(): void
    {
        $request = $this->formRequest(
            '/galleries',
            ['entries' => [['caption' => 'One'], ['caption' => 'Two'], ['caption' => 'Three']]],
            ['entries' => [
                ['image' => self::png('one.png')],
                ['image' => self::emptyControl()],
                ['image' => self::png('three.png')],
            ]],
        );

        self::assertSame(
            ['entries' => [
                ['caption' => 'One', 'image' => 'one.png'],
                ['caption' => 'Two', 'image' => null],
                ['caption' => 'Three', 'image' => 'three.png'],
            ]],
            $this->dispatchJson('/galleries', $request),
        );
    }

    /**
     * A branch naming both a sub-branch and a file is one the client
     * shaped ambiguously — `photos[0][thumb]` beside `photos[1]` — and
     * pruning the empty sub-branch away leaves a lone file behind.
     * Closing that up would move the file the client attached at `1`
     * onto position `0`, which named something else entirely, so the
     * branch keeps the key it arrived under and the list field reports
     * the shape actually sent.
     */
    public function test_a_branch_naming_a_sub_branch_beside_a_file_is_never_closed_up_over_that_file(): void
    {
        $request = $this->formRequest('/photos', [], [
            'photos' => [
                ['thumb' => self::emptyControl()],
                self::png('late.png'),
            ],
        ]);

        $violations = $this->failure('/photos', $request)->violations;

        self::assertCount(1, $violations);
        self::assertSame(['photos'], $violations[0]->path);
        self::assertSame('not_a_json_array', $violations[0]->code);
    }
}
