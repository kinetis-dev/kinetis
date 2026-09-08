<?php

declare(strict_types=1);

namespace Kinetis\McpDocs\Exception;

use RuntimeException;

/**
 * A documentation page that could not be read from its source URL — a
 * transport failure, a status other than 200 (a redirect included: the
 * client follows none), or a body past the size cap.
 */
final class DocsFetchException extends RuntimeException
{
    public static function transportFailed(string $url, string $reason): self
    {
        return new self("Could not fetch {$url}: {$reason}");
    }

    public static function unexpectedStatus(string $url, int $status): self
    {
        return new self("Could not fetch {$url}: expected status 200, got {$status}.");
    }

    public static function tooLarge(string $url, int $limitBytes): self
    {
        return new self("Could not fetch {$url}: the body exceeds the {$limitBytes}-byte cap.");
    }
}
