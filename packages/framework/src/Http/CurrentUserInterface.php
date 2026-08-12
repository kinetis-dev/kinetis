<?php

declare(strict_types=1);

namespace Kinetis\Http;

/**
 * Deliberately minimal so any auth strategy can implement it without
 * committing to one. An auth middleware resolves a user and registers it
 * on the current RequestScope (see RequestScope::class self-injection in
 * AppScope::createRequestScope()); anything downstream — a controller, a
 * different package entirely — depends on this interface instead of a
 * specific auth package's concrete class.
 *
 * Presence is the "is authenticated" signal: nothing implements or
 * registers this by default, so a controller constructor-injecting
 * CurrentUserInterface without an auth middleware having run first gets a
 * plain NotFoundException, not a null to check.
 */
interface CurrentUserInterface
{
    public function id(): string|int;
}
