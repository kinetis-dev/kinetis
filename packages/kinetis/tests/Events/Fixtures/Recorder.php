<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events\Fixtures;

final class Recorder
{
    /** @var list<string> */
    public array $messages = [];

    public function record(string $message): void
    {
        $this->messages[] = $message;
    }
}
