<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

/**
 * A nested DTO whose text field and file field arrive in separate
 * trees: `profile[name]` through the parsed body, `profile[avatar]`
 * through the uploaded files.
 */
final readonly class ProfileUploadRequest
{
    public function __construct(
        public ProfileDetails $profile,
    ) {}
}
