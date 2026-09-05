<?php

declare(strict_types=1);

namespace Kinetis\Http\Form\Exception;

use RuntimeException;

/**
 * This PHP is configured so that `parse_str()` would read a form
 * differently from the way `Kinetis\Http\Form` counted it. A deployment
 * problem rather than client input, so it is never a `400`/`413`: inside
 * the Kernel it reaches `ExceptionHandlerMiddleware`, and in an adapter,
 * where a form body is parsed before the Kernel exists, its own
 * worker-level failure path — the same route
 * {@see FormStagingException} takes.
 *
 * Refused rather than worked around, for the reason
 * {@see \Kinetis\Runtime\SuperglobalsBridge} refuses
 * `enable_post_data_reading=1`: a setting that silently changes what a
 * handler receives is worse than one that fails loudly.
 */
final class FormParserConfigurationException extends RuntimeException
{
    /**
     * `arg_separator.input` names something other than `&`, so a body
     * this framework split, counted and joined on `&` would be split
     * somewhere else by the parser it is handed to.
     */
    public static function unownedInputSeparator(string $configured): self
    {
        return new self(
            "arg_separator.input must be \"&\" so parse_str() reads a form the way Kinetis counted it, got \"{$configured}\". "
            . 'It is PHP_INI_PERDIR, so set it in php.ini, an .htaccess or the FPM pool config. '
            . 'See the "Form bodies: one contract under every runtime" section of docs/runtime-adapters.md.',
        );
    }
}
