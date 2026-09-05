<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests\Support;

use Throwable;

/**
 * Renders every ordinary way an exception's content escapes into text,
 * so one assertion can prove a value reaches none of them.
 *
 * The channels are the ones a caller reaches without meaning to: the
 * message and the messages of everything in the `previous` chain, the
 * `(string)` cast, the stack trace as a string and as a dumped array
 * (arguments included, which needs `zend.exception_ignore_args=0` — see
 * phpunit.xml), the shape a PSR-3 logger records an exception in, and
 * `serialize()`.
 *
 * The dumped-argument channel is narrowed to frames inside the package's
 * own source. A frame above it is the caller's own call, holding
 * whatever the caller passed — a test's frames included; what the
 * package answers for is the arguments its own frames carry.
 */
final class SecretChannels
{
    /**
     * @return array<string, string>
     */
    public static function of(Throwable $exception): array
    {
        $channels = [
            'message chain' => self::messageChain($exception),
            'string cast' => (string) $exception,
            'trace as string' => self::traceStrings($exception),
            'trace arguments' => self::traceDump($exception),
            'logger normalization' => self::loggerRepresentation($exception),
        ];

        try {
            $channels['serialization'] = serialize($exception);
        } catch (Throwable $failure) {
            $channels['serialization'] = 'serialization failed: ' . $failure->getMessage();
        }

        return $channels;
    }

    private static function messageChain(Throwable $exception): string
    {
        $parts = [];

        foreach (self::chain($exception) as $link) {
            $parts[] = $link::class . ': ' . $link->getMessage() . ' @ ' . $link->getFile() . ':' . $link->getLine();
        }

        return implode("\n", $parts);
    }

    private static function traceStrings(Throwable $exception): string
    {
        $parts = [];

        foreach (self::chain($exception) as $link) {
            $parts[] = $link->getTraceAsString();
        }

        return implode("\n", $parts);
    }

    private static function traceDump(Throwable $exception): string
    {
        $parts = [];

        foreach (self::chain($exception) as $link) {
            $parts[] = print_r(self::ownFrames($link), true);
        }

        return implode("\n", $parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function ownFrames(Throwable $exception): array
    {
        return array_values(array_filter($exception->getTrace(), static function (array $frame): bool {
            $class = \is_string($frame['class'] ?? null) ? $frame['class'] : '';

            return str_starts_with($class, 'Kinetis\\AwsSigV4\\')
                && !str_starts_with($class, 'Kinetis\\AwsSigV4\\Tests\\');
        }));
    }

    /**
     * The shape Monolog's own normalizer records an exception in: class,
     * message, code, file:line, the trace, and the previous chain.
     */
    private static function loggerRepresentation(Throwable $exception): string
    {
        $normalized = [];

        foreach (self::chain($exception) as $link) {
            $normalized[] = [
                'class' => $link::class,
                'message' => $link->getMessage(),
                'code' => $link->getCode(),
                'file' => $link->getFile() . ':' . $link->getLine(),
                'trace' => $link->getTraceAsString(),
            ];
        }

        return json_encode($normalized, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)
            ?: '';
    }

    /**
     * @return list<Throwable>
     */
    private static function chain(Throwable $exception): array
    {
        $chain = [];

        for ($link = $exception; $link !== null; $link = $link->getPrevious()) {
            $chain[] = $link;
        }

        return $chain;
    }
}
