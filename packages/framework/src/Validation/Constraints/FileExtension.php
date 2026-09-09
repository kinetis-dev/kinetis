<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The suffixes an uploaded file's *client-supplied* filename may end
 * with.
 *
 * This is a policy about an untrusted label, not about content. The
 * filename is written by the client and describes nothing the server
 * has verified: it is not a MIME type, it is not sniffed content, and a
 * name ending in `.png` says nothing about the bytes behind it. Use
 * this to keep an obvious mismatch out of a storage path or a
 * generated URL, and validate content — if content matters — where the
 * content is actually read.
 *
 * A suffix is written without its leading dot, and may itself contain
 * dots: `png` and `tar.gz` are both declarable, and each is matched
 * against the end of the name after the dot that separates it, so
 * `archive.tar.gz` satisfies `gz` and `tar.gz` alike while a name
 * carrying no separating dot at all satisfies neither. Comparison is
 * ASCII case-insensitive, so `PHOTO.PNG` satisfies `png`, and locale
 * plays no part in it.
 *
 * A name that is absent, empty, or ends in none of the declared
 * suffixes fails. The name itself never reaches the violation: the
 * message and parameters carry the declared choices, which the server
 * wrote, and never the string the client sent.
 *
 * {@see schema()} publishes nothing: JSON Schema has no keyword for the
 * filename of a multipart part, which the part's own
 * `{type: string, format: binary}` schema does not describe either.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class FileExtension implements Constraint
{
    /**
     * Alphanumeric segments joined by single dots, anchored at both
     * ends with `D` so a trailing newline cannot pass for the end of
     * the subject. It refuses a leading dot, an empty segment, a path
     * separator, and anything else that would compare against a
     * filename by accident rather than by declaration.
     */
    private const string SUFFIX_PATTERN = '/^[A-Za-z0-9]+(\.[A-Za-z0-9]+)*$/D';

    /**
     * @param list<string> $extensions
     */
    public function __construct(
        private array $extensions,
    ) {
        if ($extensions === [] || !array_is_list($extensions)) {
            throw new InvalidArgumentException('FileExtension extensions must be a non-empty list of strings.');
        }

        foreach ($extensions as $extension) {
            if (!is_string($extension) || preg_match(self::SUFFIX_PATTERN, $extension) !== 1) {
                throw new InvalidArgumentException(
                    'FileExtension extensions must be alphanumeric suffixes written without a leading dot, '
                    . 'such as "png" or "tar.gz", ' . var_export($extension, true) . ' given.',
                );
            }
        }
    }

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!$value instanceof UploadedFileInterface) {
            return new Violation([], 'not_a_file', 'must be an uploaded file.');
        }

        $filename = $value->getClientFilename();

        if ($filename !== null) {
            // strtolower() is ASCII-only from PHP 8.2 on, so this is the
            // locale-free comparison the docblock promises: a non-ASCII
            // byte is left exactly as it arrived and matches nothing the
            // constructor admits.
            $lowercased = strtolower($filename);

            foreach ($this->extensions as $extension) {
                if (str_ends_with($lowercased, '.' . strtolower($extension))) {
                    return null;
                }
            }
        }

        return new Violation(
            [],
            'file_extension',
            'must have one of these file extensions: ' . implode(', ', $this->extensions) . '.',
            ['choices' => $this->extensions],
        );
    }

    #[\Override]
    public function schema(): array
    {
        return [];
    }
}
