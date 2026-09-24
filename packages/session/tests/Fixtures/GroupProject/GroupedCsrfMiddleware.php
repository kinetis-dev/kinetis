<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures\GroupProject;

use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Session\Middleware\CsrfMiddleware;

/**
 * The `session` group's other member, at the attribute's default
 * priority (50) — lower than GroupedSessionMiddleware's 60, so it runs
 * after Session has already registered on the request scope. Nothing
 * beyond the attribute is added.
 */
#[AsMiddlewareGroup('session')]
final readonly class GroupedCsrfMiddleware extends CsrfMiddleware {}
