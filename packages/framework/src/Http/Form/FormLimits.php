<?php

declare(strict_types=1);

namespace Kinetis\Http\Form;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Http\Form\Exception\FormLimitExceededException;
use Kinetis\Http\Middleware\Exception\BodyTooLargeException;
use SensitiveParameter;

/**
 * The one bound on how large and how complicated a request body may be,
 * shared by every runtime adapter — the SAPI bridge core ships, and the
 * satellites that parse a form body themselves. A form the same client
 * sends to a FrankenPHP worker, a Lambda function and a RoadRunner
 * worker is accepted by all three or refused by all three, with the same
 * status.
 *
 * Seven dimensions, because a body small enough to pass a byte cap can
 * still be expensive or dangerous: a megabyte of `a[b][c][d]...=1` is
 * one variable nested thousands deep, and a megabyte of
 * `x[]=1&x[]=1&...` is a hundred thousand of them. The counts sit below
 * PHP's own defaults (`max_input_vars` 1000, `max_file_uploads` 20) on
 * purpose, so the contract — not whichever SAPI happens to be running —
 * is what a client actually meets. Where a runtime is configured *below*
 * the contract, {@see assertNamesParseable()} refuses the form rather
 * than letting that runtime's own parser quietly shorten it.
 *
 * Everything here refuses with a `413` and a message naming only the
 * limit; a body that cannot be parsed at all is the other half of the
 * policy, a `400` carrying
 * {@see \Kinetis\Runtime\RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE}.
 * Nothing truncates: a form that meets any limit here is refused whole,
 * never handed on missing exactly the fields an attacker chose to push
 * past the edge.
 *
 * **An instance, built once from `Config` and passed down.** The six
 * structural ceilings are constants because they describe the shape this
 * framework is willing to hydrate at all; the byte ceiling is a
 * per-application value, so it is a validated field of a value object
 * rather than a live `getenv()` read behind a static call. One instance
 * is built at an entry point, handed to {@see \Kinetis\Runtime\RuntimeDetector}
 * and through it to the adapter, and the same instance is what
 * {@see \Kinetis\Http\Middleware\MaxBodySizeMiddleware} enforces inside
 * the Kernel — so the byte cap a form body meets before the Kernel
 * exists and the one a raw body meets inside it cannot drift apart.
 */
final readonly class FormLimits
{
    /**
     * Leaf values in `getParsedBody()`, however they are nested. Below
     * PHP's own `max_input_vars` default of 1000 on purpose — see the
     * class docblock.
     */
    public const int MAX_INPUT_VARS = 512;

    /** Leaf entries in `getUploadedFiles()`. Below `max_file_uploads`' default of 20. */
    public const int MAX_FILE_PARTS = 16;

    /** Array levels a field or file name may build — `a[b][c]=1` is 3. */
    public const int MAX_NESTING_DEPTH = 8;

    /**
     * Parts in a `multipart/form-data` body, fields and files together,
     * counted from the raw envelope — so an unnamed part, which builds
     * neither a field nor a file, still costs one.
     */
    public const int MAX_MULTIPART_PARTS = 512;

    /**
     * Header *lines* on any one multipart part, not distinct names: a
     * part repeating one header a thousand times costs a thousand here,
     * which is the number that bounds what a parser has to hold.
     */
    public const int MAX_PART_HEADERS = 16;

    /**
     * Bytes on one multipart header line. 8 KiB is what a web server
     * accepts for a request header line and what
     * `riverline/multipart-parser` refuses past, so a line longer than
     * this is a line some parser in this framework's supported set will
     * not read — refused here, on every runtime, rather than accepted by
     * one and rejected by another.
     */
    public const int MAX_PART_HEADER_BYTES = 8_192;

    /** The byte ceiling when `MAX_BODY_SIZE` says nothing. */
    public const int DEFAULT_MAX_BODY_BYTES = 2_097_152;

    public function __construct(
        public int $maxBodyBytes,
    ) {
        if ($maxBodyBytes < 1) {
            throw new InvalidArgumentException("MAX_BODY_SIZE must be a positive number of bytes, got {$maxBodyBytes}.");
        }
    }

    public static function fromConfig(#[SensitiveParameter] Config $config): self
    {
        return new self($config->int('MAX_BODY_SIZE', self::DEFAULT_MAX_BODY_BYTES));
    }

    /**
     * Both sizes, not just the declared one. `Content-Length` is what a
     * client claims; $actualBytes is what this process is actually
     * holding, and a client that understates its length — or declares
     * none at all — is bounded only by the second.
     *
     * @param ?int $declaredBytes the `Content-Length` header when it
     *     carried a non-negative integer; null when it was absent or
     *     unusable, which is not itself an error here
     */
    public function assertBodyWithinLimit(int $actualBytes, ?int $declaredBytes): void
    {
        if ($actualBytes > $this->maxBodyBytes || ($declaredBytes !== null && $declaredBytes > $this->maxBodyBytes)) {
            throw BodyTooLargeException::exceeds($this->maxBodyBytes);
        }
    }

    public function assertMultipartPartCount(int $parts): void
    {
        if ($parts > self::MAX_MULTIPART_PARTS) {
            throw FormLimitExceededException::tooManyMultipartParts(self::MAX_MULTIPART_PARTS);
        }
    }

    public function assertPartHeaderCount(int $headerLines): void
    {
        if ($headerLines > self::MAX_PART_HEADERS) {
            throw FormLimitExceededException::tooManyPartHeaders(self::MAX_PART_HEADERS);
        }
    }

    public function assertPartHeaderLength(int $bytes): void
    {
        if ($bytes > self::MAX_PART_HEADER_BYTES) {
            throw FormLimitExceededException::partHeaderLineTooLong(self::MAX_PART_HEADER_BYTES);
        }
    }

    /**
     * Pairs counted from a raw `application/x-www-form-urlencoded` body,
     * before anything parses it. This is the only point at which the real
     * number is knowable: `a=1` repeated a thousand times is a thousand
     * pairs on the wire and one leaf afterwards, so a count taken from
     * the parsed result cannot see it at all.
     */
    public function assertRawPairCount(int $pairs): void
    {
        if ($pairs > self::MAX_INPUT_VARS) {
            throw FormLimitExceededException::tooManyInputVariables(self::MAX_INPUT_VARS);
        }
    }

    /**
     * The names a parse is about to hand `parse_str()`, checked before
     * it runs — the one point at which what the client sent is still
     * knowable.
     *
     * `parse_str()` does not report what it refused to register. A name
     * nested deeper than `max_input_nesting_level` is dropped whole and
     * in silence, and a list longer than `max_input_vars` is cut off with
     * a warning and no return value; either way the array that comes back
     * is a plausible, shorter form, and a check run on *that* is a check
     * run on the damage. So both the framework's own ceilings and this
     * runtime's ambient ones are met here, against the raw names:
     *
     * - Past {@see MAX_INPUT_VARS} or {@see MAX_NESTING_DEPTH}, the form
     *   is over this framework's contract and is refused with the same
     *   message it would get anywhere else — the contract does not move
     *   with the runtime it happens to be running on.
     * - Past `max_input_vars` or `max_input_nesting_level` while still
     *   inside the contract, the *runtime* is configured below the
     *   contract. That is refused too, naming the setting: a form this
     *   framework accepts and this PHP would silently truncate is the one
     *   outcome that must never reach a handler.
     *
     * @param list<string> $names decoded field names, in wire order
     */
    public function assertNamesParseable(array $names): void
    {
        $entries = count($names);

        if ($entries > self::MAX_INPUT_VARS) {
            throw FormLimitExceededException::tooManyInputVariables(self::MAX_INPUT_VARS);
        }

        $ambientEntries = self::ambientLimit('max_input_vars');

        if ($ambientEntries !== null && $entries > $ambientEntries) {
            throw FormLimitExceededException::sapiMayHaveTruncated('max_input_vars', $ambientEntries);
        }

        $ambientDepth = self::ambientLimit('max_input_nesting_level');

        foreach ($names as $name) {
            $depth = FormFieldName::depth($name);

            if ($depth > self::MAX_NESTING_DEPTH) {
                throw FormLimitExceededException::tooDeeplyNested(self::MAX_NESTING_DEPTH);
            }

            // `max_input_nesting_level` counts bracket levels, where a
            // depth counts the levels a name builds — one more, since
            // the name itself is the first. `a[b]` is depth 2 and one
            // bracket, and this PHP registers it at a level of 1.
            if ($ambientDepth !== null && $depth - 1 > $ambientDepth) {
                throw FormLimitExceededException::sapiMayHaveTruncated('max_input_nesting_level', $ambientDepth);
            }
        }
    }

    /**
     * A parser ceiling this PHP is configured with, or null when it is
     * configured with none. `0` and a negative value both mean unlimited
     * for these two settings, and an unreadable one is treated the same
     * way: this method exists to catch a runtime configured *below* the
     * contract, and a value that says nothing is not one.
     */
    private static function ambientLimit(string $setting): ?int
    {
        $configured = ini_get($setting);

        if (!is_string($configured) || !preg_match('/^-?\d+$/', $configured)) {
            return null;
        }

        $limit = (int) $configured;

        return $limit > 0 ? $limit : null;
    }

    /**
     * The built form, checked again after parsing: leaf counts and
     * nesting depth across both PSR-7 structures. Defense in depth behind
     * the raw counts above — a name that nested further than it read as,
     * or a count the parser arrived at differently, meets a ceiling here
     * too.
     *
     * @param array<array-key, mixed> $parsedBody
     * @param array<array-key, mixed> $uploadedFiles
     */
    public function assertFormWithinLimits(array $parsedBody, array $uploadedFiles): void
    {
        $inputVars = self::countLeaves($parsedBody, 1);
        $fileParts = self::countLeaves($uploadedFiles, 1);

        if ($inputVars > self::MAX_INPUT_VARS) {
            throw FormLimitExceededException::tooManyInputVariables(self::MAX_INPUT_VARS);
        }

        if ($fileParts > self::MAX_FILE_PARTS) {
            throw FormLimitExceededException::tooManyFileParts(self::MAX_FILE_PARTS);
        }
    }

    /**
     * Leaves, and the depth check that runs with them: one walk, so a
     * form that is both wide and deep meets whichever ceiling it
     * reaches first rather than being walked twice.
     *
     * @param array<array-key, mixed> $values
     */
    private static function countLeaves(array $values, int $depth): int
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw FormLimitExceededException::tooDeeplyNested(self::MAX_NESTING_DEPTH);
        }

        $leaves = 0;

        foreach ($values as $value) {
            $leaves += is_array($value) ? self::countLeaves($value, $depth + 1) : 1;
        }

        return $leaves;
    }
}
