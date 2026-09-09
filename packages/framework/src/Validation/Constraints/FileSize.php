<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Bounds on an uploaded file's size in bytes, read from the size the
 * multipart part itself reported. Both bounds are inclusive.
 *
 * The size is the only thing this rule reads: it never opens the
 * stream, so a file too large for the bound is refused before anything
 * has read its contents. PSR-7 lets `getSize()` answer null when the
 * size is not knowable, and a bound cannot be checked against a size
 * that does not exist — such a file fails closed rather than passing
 * unchecked.
 *
 * A negative bound, or a minimum above the maximum, is a definition
 * error refused at construction: no file could satisfy either, so the
 * declaration is wrong rather than the request.
 *
 * {@see schema()} publishes nothing. JSON Schema's string keywords
 * measure a string's own characters, and this measures the bytes of a
 * multipart part a `{type: string, format: binary}` schema stands for —
 * a different subject, whose bound stating `maxLength` would be a claim
 * requests are not checked against.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class FileSize implements Constraint
{
    public function __construct(
        private int $maxBytes,
        private int $minBytes = 0,
    ) {
        if ($maxBytes < 0) {
            throw new InvalidArgumentException("FileSize maxBytes must not be negative, got {$maxBytes}.");
        }

        if ($minBytes < 0) {
            throw new InvalidArgumentException("FileSize minBytes must not be negative, got {$minBytes}.");
        }

        if ($minBytes > $maxBytes) {
            throw new InvalidArgumentException(
                "FileSize minBytes {$minBytes} must not be greater than maxBytes {$maxBytes}.",
            );
        }
    }

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!$value instanceof UploadedFileInterface) {
            return new Violation([], 'not_a_file', 'must be an uploaded file.');
        }

        $size = $value->getSize();

        if ($size === null) {
            return new Violation([], 'file_size_unknown', 'must report a size the server can check.');
        }

        if ($size < $this->minBytes) {
            return new Violation(
                [],
                'file_too_small',
                "must be at least {$this->minBytes} bytes.",
                ['min' => $this->minBytes, 'size' => $size],
            );
        }

        if ($size > $this->maxBytes) {
            return new Violation(
                [],
                'file_too_large',
                "must be at most {$this->maxBytes} bytes.",
                ['max' => $this->maxBytes, 'size' => $size],
            );
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return [];
    }
}
