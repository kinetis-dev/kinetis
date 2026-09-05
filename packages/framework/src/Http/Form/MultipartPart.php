<?php

declare(strict_types=1);

namespace Kinetis\Http\Form;

/**
 * One `multipart/form-data` part as {@see MultipartEnvelope} read it off
 * the wire: its header lines in arrival order, the `Content-Disposition`
 * fields that decide what it becomes, and its raw body bytes.
 *
 * `name` is null for a part carrying no usable `Content-Disposition`
 * name. Such a part builds neither a field nor a file — but it is still
 * a part, and still counts against
 * {@see FormLimits::MAX_MULTIPART_PARTS}, which is the whole reason it
 * is represented rather than dropped during the scan.
 *
 * `filename` is null when the part declared none (a plain field) and the
 * empty string when it declared an empty one, which is what a browser
 * sends for a file input the user left alone. The two are different
 * things and PHP tells them apart, so this does too — see
 * {@see MultipartFormBuilder::addFile()}.
 */
final readonly class MultipartPart
{
    /**
     * @param list<array{0: string, 1: string}> $headers name/value pairs
     *     in arrival order, repeats included
     */
    public function __construct(
        public array $headers,
        public ?string $name,
        public ?string $filename,
        public ?string $contentType,
        public string $body,
    ) {}

    public function isFile(): bool
    {
        return $this->filename !== null;
    }
}
