<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Form;

use Kinetis\Http\Form\Exception\FormLimitExceededException;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\Form\MultipartFormBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The nesting rules an adapter parsing a multipart body itself has to
 * reproduce, checked against the shapes a SAPI produces for the same
 * part names. The runtime conformance suite proves the two agree end to
 * end; this pins each rule on its own, where a failure says which one
 * broke.
 */
final class MultipartFormBuilderTest extends TestCase
{
    public function test_bracketed_names_nest_and_bare_repeats_replace(): void
    {
        $builder = new MultipartFormBuilder(new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES));
        $builder->addField('user[address][city]', 'Tel Aviv');
        $builder->addField('user[name]', 'Alon');
        $builder->addField('tags[]', 'first');
        $builder->addField('tags[]', 'second');
        $builder->addField('replaced', 'earlier');
        $builder->addField('replaced', 'later');

        [$parsedBody, $files] = $builder->build();

        self::assertSame(
            [
                'user' => ['address' => ['city' => 'Tel Aviv'], 'name' => 'Alon'],
                'tags' => ['first', 'second'],
                'replaced' => 'later',
            ],
            $parsedBody,
        );
        self::assertSame([], $files);
    }

    /**
     * A part name is client text and can carry anything, `&` and `=`
     * included. Each bracket segment is encoded before `parse_str()`
     * sees it, so a name like this stays one field instead of splitting
     * into several — the same name PHP's own parser registers.
     */
    public function test_a_field_name_carrying_separator_characters_stays_one_field(): void
    {
        $builder = new MultipartFormBuilder(new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES));
        $builder->addField('weird&=name', 'x');
        $builder->addField('a[b', 'y');

        [$parsedBody] = $builder->build();

        self::assertSame(['weird&=name' => 'x', 'a_b' => 'y'], $parsedBody);
    }

    /**
     * A file input the user left alone: an empty part with an empty
     * filename. PHP reports it in `$_FILES` as present with
     * `UPLOAD_ERR_NO_FILE` and no name, type or bytes — verified against
     * a real SAPI — so validation written against PHP reads "nothing was
     * chosen". Reporting it as a successful zero-byte upload instead
     * would make that validation accept here what it rejects there.
     */
    public function test_an_empty_file_control_is_reported_the_way_php_reports_it(): void
    {
        $builder = new MultipartFormBuilder(new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES));
        $builder->addFile('avatar', '', 'application/octet-stream', '');

        [$parsedBody, $files] = $builder->build();

        self::assertSame([], $parsedBody, 'an empty file control is not a field');
        self::assertInstanceOf(UploadedFileInterface::class, $files['avatar']);
        self::assertSame(UPLOAD_ERR_NO_FILE, $files['avatar']->getError());
        self::assertSame(0, $files['avatar']->getSize());
        self::assertSame('', $files['avatar']->getClientFilename());
        self::assertSame('', $files['avatar']->getClientMediaType());
    }

    /**
     * A file that carries bytes under an empty filename is a real
     * upload, not an empty control — the two differ by whether anything
     * was sent, so only the empty one is reported as absent.
     */
    public function test_a_named_part_carrying_bytes_is_a_real_upload_even_with_an_empty_filename(): void
    {
        $builder = new MultipartFormBuilder(new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES));
        $builder->addFile('avatar', '', 'text/plain', 'bytes');

        [, $files] = $builder->build();

        self::assertSame(UPLOAD_ERR_OK, $files['avatar']->getError());
        self::assertSame('bytes', (string) $files['avatar']->getStream());
    }

    public function test_repeated_and_nested_file_names_build_the_tree_php_builds(): void
    {
        $builder = new MultipartFormBuilder(new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES));
        $builder->addFile('docs[]', 'one.txt', 'text/plain', 'first');
        $builder->addFile('docs[]', 'two.txt', 'text/plain', 'second');
        $builder->addFile('profile[avatar]', 'avatar.png', 'image/png', 'png');

        [, $files] = $builder->build();

        self::assertInstanceOf(UploadedFileInterface::class, $files['docs'][0]);
        self::assertSame('one.txt', $files['docs'][0]->getClientFilename());
        self::assertSame('two.txt', $files['docs'][1]->getClientFilename());
        self::assertSame('second', (string) $files['docs'][1]->getStream());
        self::assertSame('avatar.png', $files['profile']['avatar']->getClientFilename());
        self::assertSame('image/png', $files['profile']['avatar']->getClientMediaType());
    }

    public function test_the_built_form_is_held_to_the_file_ceiling(): void
    {
        $builder = new MultipartFormBuilder(new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES));

        for ($i = 0; $i <= FormLimits::MAX_FILE_PARTS; $i++) {
            $builder->addFile("docs[{$i}]", "doc{$i}.txt", 'text/plain', 'bytes');
        }

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('file parts');

        $builder->build();
    }
}
