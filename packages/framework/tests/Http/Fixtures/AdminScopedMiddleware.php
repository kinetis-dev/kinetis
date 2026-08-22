<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\RoutePrefix;

/**
 * A second, distinct prefixed middleware from VersionedMiddleware — used
 * alongside it to prove ordering across multiple prefix-carrying
 * middleware, not just that one alone works.
 */
#[RoutePrefix('/admin')]
final class AdminScopedMiddleware extends RecordingMiddleware {}
