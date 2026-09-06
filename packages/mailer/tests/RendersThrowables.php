<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Throwable;

/**
 * Every way an exception ordinarily becomes text: a handler's message,
 * a logger's `__toString()`, a debug dump, a serialized copy, and the
 * raw trace a framework may walk itself.
 */
trait RendersThrowables
{
    /**
     * @param list<string> $sentinels
     */
    private function assertNoSentinelSurvives(Throwable $e, array $sentinels): void
    {
        foreach ($this->renderingsOf($e) as $label => $rendering) {
            foreach ($sentinels as $sentinel) {
                self::assertStringNotContainsString($sentinel, $rendering, "a sentinel reached {$label}");
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function renderingsOf(Throwable $e): array
    {
        try {
            // A trace frame holding a SensitiveParameterValue refuses to
            // serialize at all, which is the safe outcome rather than a
            // redacted one.
            $serialized = serialize($e);
        } catch (Throwable $failure) {
            $serialized = $failure->getMessage();
        }

        return [
            'the message' => $e->getMessage(),
            'the stringification' => (string) $e,
            'the trace' => $e->getTraceAsString(),
            'a debug dump' => print_r($e, true),
            'a var_dump' => $this->dump($e),
            'a serialized copy' => $serialized,
            'the raw trace' => print_r($e->getTrace(), true),
            'the visible properties' => print_r(get_object_vars($e), true),
        ];
    }

    private function dump(Throwable $e): string
    {
        ob_start();
        var_dump($e);

        return (string) ob_get_clean();
    }
}
