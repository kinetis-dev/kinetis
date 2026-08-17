<?php

declare(strict_types=1);

namespace Kinetis\OpenApi;

use Kinetis\Config\Config;

/**
 * Whether this process serves `/openapi.json` and `/openapi`.
 *
 * Decided at request time rather than when routes are registered. The
 * routes themselves always exist — registering them conditionally would
 * push the decision into `bin/kinetis build`, and a production image
 * would then answer according to whichever environment compiled it
 * rather than the one it is running in.
 */
final readonly class OpenApiAccess
{
    public function __construct(private bool $enabled) {}

    /**
     * OPENAPI_ENVIRONMENTS is a comma-separated list of APP_ENV values,
     * matched ignoring case and surrounding space. Unset names no
     * environment, so both paths stay closed until an application opts
     * in: together they describe the whole route table, which is
     * reconnaissance rather than a vulnerability, but there is no
     * version of publishing it that an application chose.
     *
     * Matched against the raw APP_ENV string rather than
     * {@see \Kinetis\Runtime\AppEnvironment}, which resolves every
     * unrecognized name to Production — a deployment with a `staging`
     * environment can name it here and have it mean staging.
     */
    public static function fromConfig(Config $config): self
    {
        $environments = \array_filter(\array_map(
            static fn (string $name): string => \strtolower(\trim($name)),
            \explode(',', $config->string('OPENAPI_ENVIRONMENTS', '')),
        ), static fn (string $name): bool => $name !== '');

        $current = \strtolower(\trim($config->string('APP_ENV', '')));

        // Matching AppEnvironment::detect(): an absent APP_ENV is
        // production, so a deployment that sets nothing is still
        // addressable by name.
        return new self(\in_array($current === '' ? 'production' : $current, $environments, true));
    }

    public static function enabled(): self
    {
        return new self(true);
    }

    public static function disabled(): self
    {
        return new self(false);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
