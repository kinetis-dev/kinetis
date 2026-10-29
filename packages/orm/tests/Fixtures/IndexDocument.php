<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Queue\Job;

/** A job carrying a StoredDocument's application-assigned identifier. */
final readonly class IndexDocument implements Job
{
    public function __construct(public string $documentId)
    {
    }
}
