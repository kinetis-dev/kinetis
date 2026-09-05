<?php

declare(strict_types=1);

namespace Kinetis\Http\Form;

use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Collects the parts of a `multipart/form-data` body and turns them
 * into the two structures PSR-7 hands a handler: `getParsedBody()` and
 * `getUploadedFiles()`.
 *
 * The part of this that is easy to get wrong, and that every adapter
 * parsing a body itself would otherwise get wrong separately, is that a
 * part name is not a key. `user[address][city]` nests, `tags[]` appends,
 * and a repeated `avatar` replaces — under a SAPI, because PHP's own
 * parser says so. Assigning `$fields[$name]` instead flattens all three
 * into something a handler reads differently depending on which runtime
 * it happens to be on. Both structures here are therefore built by
 * `parse_str()`, PHP's own implementation of those rules, reached
 * through {@see FormPairs}: fields directly, files by parsing a tree of
 * positions and swapping each one for its {@see UploadedFile}, so one
 * rule shapes both.
 *
 * Part and header-line counts are {@see MultipartEnvelope}'s, taken from
 * the raw body before anything reaches this class. The rest of the
 * ceilings are met here, and on the same principle: the part names are
 * checked before each parse, since a name nested past this runtime's
 * own `max_input_nesting_level` is dropped whole and without a word,
 * and leaf counts are checked on the two structures afterwards,
 * where they first exist. A form past any of them is refused with a
 * `413` before a handler sees any of it.
 */
final class MultipartFormBuilder
{
    /** @var list<array{name: string, value: string}> */
    private array $fields = [];

    /** @var list<array{name: string, file: UploadedFileInterface}> */
    private array $files = [];

    public function __construct(
        private readonly FormLimits $limits,
    ) {}

    public function addField(string $name, string $value): void
    {
        $this->fields[] = ['name' => $name, 'value' => $value];
    }

    /**
     * A file part, with PHP's own empty-file semantics preserved.
     *
     * A file input the user left alone is still submitted: the browser
     * sends a part with `filename=""` and no bytes. PHP represents that
     * in `$_FILES` as a present entry with `error` `UPLOAD_ERR_NO_FILE`,
     * an empty name and type, and size 0, so upload validation written
     * against PHP sees "no file was chosen" and rejects it. Reporting the same part as a
     * successful zero-byte upload instead would make that validation
     * accept, under an adapter with its own parser, exactly what it
     * rejects under FrankenPHP or PHP-FPM.
     *
     * `getStream()` on such an entry throws, as PSR-7 requires for any
     * non-`UPLOAD_ERR_OK` file, so nothing downstream can read bytes
     * that were never sent.
     */
    public function addFile(string $name, ?string $filename, ?string $mediaType, string $contents): void
    {
        $isEmptyUpload = ($filename ?? '') === '' && $contents === '';

        $this->files[] = [
            'name' => $name,
            'file' => $isEmptyUpload
                ? new UploadedFile(Stream::create(''), 0, UPLOAD_ERR_NO_FILE, '', '')
                : new UploadedFile(Stream::create($contents), strlen($contents), UPLOAD_ERR_OK, $filename, $mediaType),
        ];
    }

    /**
     * @return array{0: array<array-key, mixed>, 1: array<array-key, mixed>}
     */
    public function build(): array
    {
        $parsedBody = $this->parsedBody();
        $uploadedFiles = $this->uploadedFiles();

        $this->limits->assertFormWithinLimits($parsedBody, $uploadedFiles);

        return [$parsedBody, $uploadedFiles];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parsedBody(): array
    {
        $this->limits->assertNamesParseable(array_column($this->fields, 'name'));

        $pairs = array_map(
            static fn (array $field): string => FormFieldName::encode($field['name']) . '=' . rawurlencode($field['value']),
            $this->fields,
        );

        return FormPairs::parse(implode(FormPairs::SEPARATOR, $pairs));
    }

    /**
     * Each file's position in {@see $files} is registered under its own
     * name, so the parse builds the identical shape it would have built
     * for fields of the same names — appends, nesting and replacement
     * included — and each position is then swapped for the file it
     * stands for.
     *
     * @return array<array-key, mixed>
     */
    private function uploadedFiles(): array
    {
        $this->limits->assertNamesParseable(array_column($this->files, 'name'));

        $pairs = [];

        foreach ($this->files as $position => $file) {
            $pairs[] = FormFieldName::encode($file['name']) . '=' . $position;
        }

        return $this->resolvePositions(FormPairs::parse(implode(FormPairs::SEPARATOR, $pairs)));
    }

    /**
     * @param array<array-key, mixed> $positions
     * @return array<array-key, mixed>
     */
    private function resolvePositions(array $positions): array
    {
        $resolved = [];

        foreach ($positions as $key => $value) {
            if (is_array($value)) {
                $resolved[$key] = $this->resolvePositions($value);

                continue;
            }

            // Always a position this method wrote itself, never client
            // text: the parse only ever hands back the values it was
            // given, and every one of them was an array index above.
            $resolved[$key] = $this->files[(int) $value]['file'];
        }

        return $resolved;
    }
}
