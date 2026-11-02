<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs;

/**
 * The outcome of retrying one failed-job record — see {@see RetryResult}.
 */
enum RetryStatus: string
{
    /** The job was pushed back onto its queue and its record forgotten. */
    case Retried = 'retried';

    /** No record has that id. */
    case NotFound = 'not_found';

    /** The job could not be pushed; its record is kept. */
    case Failed = 'failed';
}
