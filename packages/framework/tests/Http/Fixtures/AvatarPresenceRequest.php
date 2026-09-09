<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Validation\Absent;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The three answers an upload field gives when the browser sends the
 * control empty: a nullable defaulted field takes its default, and a
 * presence union reports the omission the client actually made.
 */
final readonly class AvatarPresenceRequest
{
    public function __construct(
        public ?UploadedFileInterface $avatar = null,
        public UploadedFileInterface|Absent $thumbnail = Absent::Value,
    ) {}
}
