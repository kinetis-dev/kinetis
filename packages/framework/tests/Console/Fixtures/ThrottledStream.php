<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console\Fixtures;

use RuntimeException;

/**
 * A stream wrapper whose every fwrite() call ends short: within one call
 * it accepts at most $acceptPerCall bytes, then refuses the rest, so
 * fwrite() reports a partial write the caller has to complete.
 */
final class ThrottledStream
{
    private const string PROTOCOL = 'kinetis-throttled';

    public static string $written = '';

    private static int $acceptPerCall = 0;

    /** @var resource|null */
    public $context;

    private bool $refuseNext = false;

    /**
     * @return resource
     */
    public static function open(int $acceptPerCall): mixed
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        self::$written = '';
        self::$acceptPerCall = $acceptPerCall;

        $stream = fopen(self::PROTOCOL . '://out', 'w');

        if ($stream === false) {
            throw new RuntimeException('Could not open the throttled stream.');
        }

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    /**
     * PHP retries a short user-wrapper write itself until the wrapper
     * returns 0, so alternating an accepted chunk with a refusal is what
     * makes fwrite() itself return fewer bytes than it was given.
     */
    public function stream_write(string $data): int
    {
        if ($this->refuseNext) {
            $this->refuseNext = false;

            return 0;
        }

        $this->refuseNext = true;
        $accepted = substr($data, 0, self::$acceptPerCall);
        self::$written .= $accepted;

        return strlen($accepted);
    }
}
