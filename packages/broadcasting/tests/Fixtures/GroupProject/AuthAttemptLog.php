<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures\GroupProject;

/**
 * A plain mutable counter the application's own group middleware
 * increments, registered as a singleton instance on the container — so a
 * test can assert that middleware never ran at all for a request the
 * origin check refused.
 */
final class AuthAttemptLog
{
    public int $attempts = 0;
}
