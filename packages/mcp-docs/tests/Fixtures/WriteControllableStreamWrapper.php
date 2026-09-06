<?php

declare(strict_types=1);

namespace Kinetis\McpDocs\Tests\Fixtures;

/**
 * A registered stream wrapper whose write() behavior is driven by
 * context options, so StdioLoopTest can produce a short write
 * (fwrite() accepting fewer bytes than it was given) or a failed one
 * against a real PHP resource without needing a broken pipe.
 *
 * Context options live under the self::PROTOCOL key:
 * - writeReturns: list<int|false>, one forced return per fwrite() call,
 *   consumed in order. `false` fails that call with nothing written; an
 *   int shorter than the data buffers only that many bytes. Once the
 *   list runs out, writes behave normally.
 *
 * PHP builds the wrapper itself on fopen(), which is why configuration
 * travels through stream_context_create() rather than a constructor.
 *
 * @internal test fixture only
 */
final class WriteControllableStreamWrapper
{
    public const string PROTOCOL = 'kinetis-mcp-docs-test-write-controllable-stream';

    /** @var resource */
    public $context;

    private string $buffer = '';

    private int $position = 0;

    /** @var list<int|false> */
    private array $writeReturns = [];

    public static function register(): void
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $config = stream_context_get_options($this->context)[self::PROTOCOL] ?? [];
        $this->writeReturns = $config['writeReturns'] ?? [];

        return true;
    }

    public function stream_write(string $data): int|false
    {
        if ($this->writeReturns !== []) {
            $forced = array_shift($this->writeReturns);

            if ($forced === false) {
                return false;
            }

            $consumed = min($forced, strlen($data));
            $this->buffer = substr_replace($this->buffer, substr($data, 0, $consumed), $this->position, $consumed);
            $this->position += $consumed;

            return $consumed;
        }

        $length = strlen($data);
        $this->buffer = substr_replace($this->buffer, $data, $this->position, $length);
        $this->position += $length;

        return $length;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->buffer, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->buffer);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($whence !== SEEK_SET || $offset < 0) {
            return false;
        }

        $this->position = $offset;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
    }
}
