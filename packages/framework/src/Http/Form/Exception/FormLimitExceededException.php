<?php

declare(strict_types=1);

namespace Kinetis\Http\Form\Exception;

use RuntimeException;

/**
 * A form body the client sent is within nothing's ability to accept: it
 * declares or contains more than {@see \Kinetis\Http\Form\FormLimits}
 * allows. Always answered with a `413`, on every adapter.
 *
 * Every message names a limit and its configured ceiling and nothing
 * else — no field name, no value, no byte from the request — so unlike
 * {@see \Kinetis\Runtime\RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE}'s
 * fixed text this one is safe to return to the client verbatim, the
 * same policy {@see \Kinetis\Http\Middleware\Exception\BodyTooLargeException}
 * already follows for the byte count.
 */
final class FormLimitExceededException extends RuntimeException
{
    public static function tooManyInputVariables(int $maxInputVars): self
    {
        return new self("Request form exceeds the maximum of {$maxInputVars} input variables.");
    }

    public static function tooManyFileParts(int $maxFileParts): self
    {
        return new self("Request form exceeds the maximum of {$maxFileParts} file parts.");
    }

    public static function tooDeeplyNested(int $maxDepth): self
    {
        return new self("Request form exceeds the maximum nesting depth of {$maxDepth}.");
    }

    public static function tooManyMultipartParts(int $maxParts): self
    {
        return new self("Request form exceeds the maximum of {$maxParts} multipart parts.");
    }

    public static function tooManyPartHeaders(int $maxHeaders): self
    {
        return new self("A multipart part exceeds the maximum of {$maxHeaders} headers.");
    }

    public static function partHeaderLineTooLong(int $maxBytes): self
    {
        return new self("A multipart part header line exceeds the maximum of {$maxBytes} bytes.");
    }

    /**
     * This runtime is configured below the contract: a form this
     * framework accepts names more variables, or nests them deeper, than
     * the local `max_input_vars`/`max_input_nesting_level` will register.
     * `parse_str()` answers that by dropping what it would not take —
     * silently, for a name nested too deep — so the form is refused
     * before it is parsed. A form missing exactly the fields an attacker
     * chose to push past the limit is more dangerous than no form at
     * all.
     */
    public static function sapiMayHaveTruncated(string $setting, int $limit): self
    {
        return new self("Request form reaches this runtime's own {$setting} limit of {$limit} and may be incomplete.");
    }
}
