<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Constraints;

use InvalidArgumentException;
use Kinetis\Tests\Fixtures\UnreadableUploadedFile;
use Kinetis\Validation\Constraint;
use Kinetis\Validation\Constraints\FileExtension;
use Kinetis\Validation\Constraints\FileMediaType;
use Kinetis\Validation\Constraints\FileSize;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * The rules that describe an uploaded file. #[FileSize] and
 * #[FileExtension] read the part's own metadata and nothing else, so
 * every file they are given here is one whose stream throws: a rule
 * that reached for content would fail with that exception instead of
 * answering. #[FileMediaType] reads the content, so its files carry
 * real bytes.
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

    // --- FileMediaType ---

    private const string PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";
    private const string JPEG = "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xff\xd9";
    private const string PDF = "%PDF-1.4\n%%EOF\n";

    /**
     * A file shaped as the multipart parser builds one, over an
     * in-memory stream at offset 0.
     */
    private static function upload(string $contents, string $filename, string $mediaType): UploadedFile
    {
        return new UploadedFile(Stream::create($contents), strlen($contents), UPLOAD_ERR_OK, $filename, $mediaType);
    }

    #[DataProvider('detectedContents')]
    public function test_content_detected_as_a_declared_type_is_accepted(string $contents, string $type): void
    {
        $rule = new FileMediaType(['application/zip', $type]);

        self::assertNull($rule->validate(self::upload($contents, 'upload.bin', 'application/octet-stream')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function detectedContents(): iterable
    {
        yield 'a PNG' => [self::PNG, 'image/png'];
        yield 'a JPEG' => [self::JPEG, 'image/jpeg'];
        yield 'a PDF' => [self::PDF, 'application/pdf'];
    }

    /**
     * The client's filename and media type both say JPEG. The bytes are
     * a PNG, and the rule answers from the bytes.
     */
    public function test_a_mislabelled_file_is_refused_with_the_detected_type(): void
    {
        $violation = new FileMediaType(['image/jpeg'])->validate(self::upload(self::PNG, 'photo.jpg', 'image/jpeg'));

        self::assertNotNull($violation);
        self::assertSame('file_media_type', $violation->code);
        self::assertSame('must be one of these media types: image/jpeg.', $violation->message);
        self::assertSame(['choices' => ['image/jpeg'], 'media_type' => 'image/png'], $violation->parameters);
    }

    public function test_a_declared_type_matches_regardless_of_ascii_case(): void
    {
        self::assertNull(new FileMediaType(['Image/PNG'])->validate(self::upload(self::PNG, 'a.png', 'image/png')));
    }

    /**
     * The handler shares the stream instance the rule read, so the rule
     * leaves it at offset 0 wherever it started. The large case crosses
     * Nyholm's threshold for its in-memory string wrapper.
     */
    #[DataProvider('streamSizes')]
    public function test_the_stream_is_left_rewound_for_the_handler(string $contents): void
    {
        $file = self::upload($contents, 'a.png', 'image/png');
        $file->getStream()->seek(5);

        self::assertNull(new FileMediaType(['image/png'])->validate($file));
        self::assertSame(0, $file->getStream()->tell());
        self::assertSame($contents, $file->getStream()->getContents());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function streamSizes(): iterable
    {
        yield 'a small part' => [self::PNG];
        yield 'a part above 200000 bytes' => [self::PNG . str_repeat("\x00", 200_000)];
    }

    /**
     * A stream that cannot be opened is a host or transport fault, not
     * a property of the request, so it propagates rather than becoming
     * a violation.
     */
    public function test_a_stream_that_cannot_be_opened_propagates(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This uploaded file must never be opened.');

        new FileMediaType(['image/png'])->validate(self::file(1, 'a.png'));
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
        yield 'no media types at all' => [
            static fn (): Constraint => new FileMediaType([]),
            'FileMediaType types must be a non-empty list of strings.',
        ];
        yield 'keyed media types' => [
            static fn (): Constraint => new FileMediaType(['image' => 'image/png']),
            'FileMediaType types must be a non-empty list of strings.',
        ];
        yield 'a wildcard subtype' => [
            static fn (): Constraint => new FileMediaType(['image/*']),
            "'image/*' given.",
        ];
        yield 'a parameter' => [
            static fn (): Constraint => new FileMediaType(['text/plain; charset=utf-8']),
            "'text/plain; charset=utf-8' given.",
        ];
        yield 'no subtype' => [
            static fn (): Constraint => new FileMediaType(['image']),
            "'image' given.",
        ];
        yield 'an empty subtype' => [
            static fn (): Constraint => new FileMediaType(['image/']),
            "'image/' given.",
        ];
        yield 'surrounding whitespace' => [
            static fn (): Constraint => new FileMediaType([' image/png']),
            "' image/png' given.",
        ];
        yield 'a media type with a trailing newline' => [
            static fn (): Constraint => new FileMediaType(["image/png\n"]),
            'given.',
        ];
        yield 'a non-string media type' => [
            static fn (): Constraint => new FileMediaType([7]),
            '7 given.',
        ];
    }
}
