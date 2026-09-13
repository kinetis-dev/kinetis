<?php

declare(strict_types=1);

namespace Kinetis\Testing\Exception;

use RuntimeException;

final class LoopLivenessInconclusiveException extends RuntimeException
{
    public static function operationFinishedBeforeSentinel(float $elapsedSeconds, float $sentinelSeconds): self
    {
        return new self(sprintf(
            'The operation finished in %.6F s, before the %.6F s sentinel interval, so it cannot show whether '
            . 'the event loop turned. Make the operation outlast the sentinel.',
            $elapsedSeconds,
            $sentinelSeconds,
        ));
    }
}
