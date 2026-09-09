<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Constraints;

use InvalidArgumentException;
use Kinetis\Tests\Fixtures\UnreadableUploadedFile;
use Kinetis\Validation\Constraint;
use Kinetis\Validation\Constraints\FileExtension;
use Kinetis\Validation\Constraints\FileSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * The two rules that describe an uploaded file. Both read the part's
 * own metadata and nothing else, so every file here is one whose stream
 * throws: a rule that reached for content would fail with that
 * exception instead of answering.
 */
final class FileRulesTest extends TestCase
{
    private static function file(?int $size = null, ?string $filename = null): UploadedFileInterface
    {
        return new UnreadableUploadedFile(UPLOAD_ERR_OK, $size, $filename);
    }

    // --- FileSize ---

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('sizes')]
    public function test_a_size_outside_the_bounds_reports_which_bound_and_the_size(
        FileSize $rule,
        ?int $size,
        ?string $code,
        array $parameters,
    ): void {
        $violation = $rule->validate(self::file($size));

        if ($code === null) {
            self::assertNull($violation);

            return;
        }

        self::assertNotNull($violation);
        self::assertSame($code, $violation->code);
        self::assertSame($parameters, $violation->parameters);
    }

    /**
     * @return iterable<string, array{FileSize, ?int, ?string, array<string, mixed>}>
     */
    public static function sizes(): iterable
    {
        yield 'within both bounds' => [new FileSize(100, 10), 50, null, []];
        yield 'exactly the minimum' => [new FileSize(100, 10), 10, null, []];
        yield 'exactly the maximum' => [new FileSize(100, 10), 100, null, []];
        yield 'one byte under the minimum' => [
            new FileSize(100, 10),
            9,
            'file_too_small',
            ['min' => 10, 'size' => 9],
        ];
        yield 'one byte over the maximum' => [
            new FileSize(100, 10),
            101,
            'file_too_large',
            ['max' => 100, 'size' => 101],
        ];
        yield 'an empty file under a default minimum' => [new FileSize(100), 0, null, []];
        // PSR-7 permits a null size, and a bound cannot be checked
        // against a size that does not exist. The violation carries no
        // size at all rather than inventing one.
        yield 'a size the transport never reported' => [new FileSize(100), null, 'file_size_unknown', []];
    }

    public function test_an_unknown_size_says_so_without_naming_a_size(): void
    {
        $violation = new FileSize(10)->validate(self::file(null));

        self::assertNotNull($violation);
        self::assertSame('file_size_unknown', $violation->code);
        self::assertSame('must report a size the server can check.', $violation->message);
        self::assertSame([], $violation->parameters);
    }

    /**
     * The size is read once and the stream never. An implementation
     * that consulted getSize() per bound, or measured the stream to
     * cross-check it, fails here rather than in production against a
     * file the transport streamed straight to disk.
     */
    public function test_a_size_check_reads_the_size_once_and_never_the_stream(): void
    {
        $file = new class implements UploadedFileInterface {
            public int $sizeReads = 0;

            #[\Override]
            public function getStream(): StreamInterface
            {
                throw new RuntimeException('A size check must not open the stream.');
            }

            #[\Override]
            public function moveTo(string $targetPath): void
            {
                throw new RuntimeException('A size check must not move the file.');
            }

            #[\Override]
            public function getSize(): ?int
            {
                $this->sizeReads++;

                return 500;
            }

            #[\Override]
            public function getError(): int
            {
                return UPLOAD_ERR_OK;
            }

            #[\Override]
            public function getClientFilename(): ?string
            {
                return null;
            }

            #[\Override]
            public function getClientMediaType(): ?string
            {
                return null;
            }
        };

        self::assertNotNull(new FileSize(100, 10)->validate($file));
        self::assertSame(1, $file->sizeReads);
    }

    // --- FileExtension ---

    /**
     * @param list<string> $choices
     */
    #[DataProvider('filenames')]
    public function test_a_client_filename_is_matched_by_its_final_suffix(
        array $choices,
        ?string $filename,
        bool $accepted,
    ): void {
        $violation = new FileExtension($choices)->validate(self::file(1, $filename));

        self::assertSame($accepted, $violation === null);
    }

    /**
     * @return iterable<string, array{list<string>, ?string, bool}>
     */
    public static function filenames(): iterable
    {
        yield 'the declared suffix' => [['png'], 'photo.png', true];
        yield 'an uppercase filename' => [['png'], 'PHOTO.PNG', true];
        yield 'an uppercase declaration' => [['PNG'], 'photo.png', true];
        yield 'the second of two choices' => [['jpg', 'png'], 'photo.png', true];
        // A compound suffix and its own tail are separate declarations,
        // and each matches the end of the name after the dot that
        // separates it.
        yield 'a compound suffix' => [['tar.gz'], 'archive.tar.gz', true];
        yield 'the tail of a compound suffix' => [['gz'], 'archive.tar.gz', true];
        yield 'a compound suffix against a plain one' => [['tar.gz'], 'archive.gz', false];
        yield 'a different suffix' => [['png'], 'photo.gif', false];
        // The suffix is what follows a dot. A name that is exactly the
        // word has no suffix at all.
        yield 'no separating dot' => [['png'], 'png', false];
        yield 'a suffix that is only part of the last segment' => [['png'], 'photo.spng', false];
        yield 'a trailing dot' => [['png'], 'photo.', false];
        yield 'an empty filename' => [['png'], '', false];
        yield 'a filename the client never sent' => [['png'], null, false];
    }

    public function test_a_refused_filename_reports_the_declared_choices_and_never_the_name(): void
    {
        $violation = new FileExtension(['png', 'tar.gz'])->validate(self::file(1, 'secret-report.txt'));

        self::assertNotNull($violation);
        self::assertSame('file_extension', $violation->code);
        self::assertSame('must have one of these file extensions: png, tar.gz.', $violation->message);
        self::assertSame(['choices' => ['png', 'tar.gz']], $violation->parameters);
        self::assertStringNotContainsString('secret-report', $violation->message);
    }

    // --- Definition errors ---

    /**
     * @param callable(): Constraint $definition
     */
    #[DataProvider('rejectedDefinitions')]
    public function test_a_definition_no_file_could_satisfy_is_refused(callable $definition, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $definition();
    }

    /**
     * @return iterable<string, array{callable(): Constraint, string}>
     */
    public static function rejectedDefinitions(): iterable
    {
        yield 'a negative maximum' => [
            static fn (): Constraint => new FileSize(-1),
            'FileSize maxBytes must not be negative, got -1.',
        ];
        yield 'a negative minimum' => [
            static fn (): Constraint => new FileSize(10, -1),
            'FileSize minBytes must not be negative, got -1.',
        ];
        yield 'a minimum above the maximum' => [
            static fn (): Constraint => new FileSize(5, 10),
            'FileSize minBytes 10 must not be greater than maxBytes 5.',
        ];
        yield 'no extensions at all' => [
            static fn (): Constraint => new FileExtension([]),
            'FileExtension extensions must be a non-empty list of strings.',
        ];
        yield 'keyed extensions' => [
            static fn (): Constraint => new FileExtension(['image' => 'png']),
            'FileExtension extensions must be a non-empty list of strings.',
        ];
        yield 'a leading dot' => [
            static fn (): Constraint => new FileExtension(['.png']),
            "'.png' given.",
        ];
        yield 'a path separator' => [
            static fn (): Constraint => new FileExtension(['a/b']),
            "'a/b' given.",
        ];
        yield 'an empty token' => [
            static fn (): Constraint => new FileExtension(['']),
            "'' given.",
        ];
        yield 'an empty segment inside a compound token' => [
            static fn (): Constraint => new FileExtension(['tar..gz']),
            "'tar..gz' given.",
        ];
        yield 'a trailing dot' => [
            static fn (): Constraint => new FileExtension(['tar.']),
            "'tar.' given.",
        ];
        yield 'a trailing newline' => [
            static fn (): Constraint => new FileExtension(["png\n"]),
            'given.',
        ];
        yield 'a non-string token' => [
            static fn (): Constraint => new FileExtension([7]),
            '7 given.',
        ];
    }
}
