<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures\GroupProject;

use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Session\Middleware\SessionMiddleware;

/**
 * The one supported reason SessionMiddleware is not final: an attribute
 * attaches to a class only by being declared on one, so joining a
 * middleware group needs a class to carry #[AsMiddlewareGroup]. Priority
 * 60 outranks CsrfMiddleware's own default, 50 — Session has to run more
 * outer than Csrf, since Csrf reads the Session this class registers.
 * Nothing else is added.
 */
#[AsMiddlewareGroup('session', priority: 60)]
final readonly class GroupedSessionMiddleware extends SessionMiddleware {}
