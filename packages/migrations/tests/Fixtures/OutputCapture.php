<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Fixtures;

use php_user_filter;

/**
 * Captures what a command writes to STDOUT or STDERR — which output
 * buffering never sees — by attaching a write filter that keeps every
 * byte and passes none of them on.
 */
final class OutputCapture extends php_user_filter
{
    private const string NAME = 'kinetis.bridge-output-capture';

    private static string $captured = '';

    /**
     * @param resource $stream
     * @param callable(): int $run
     * @return array{int, string} the exit code and what was written
     */
    public static function of($stream, callable $run): array
    {
        if (!\in_array(self::NAME, \stream_get_filters(), true)) {
            \stream_filter_register(self::NAME, self::class);
        }

        self::$captured = '';
        $filter = \stream_filter_append($stream, self::NAME, \STREAM_FILTER_WRITE);

        try {
            $exitCode = $run();
        } finally {
            \stream_filter_remove($filter);
        }

        return [$exitCode, self::$captured];
    }

    #[\Override]
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = \stream_bucket_make_writeable($in)) {
            self::$captured .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        return \PSFS_PASS_ON;
    }
}
