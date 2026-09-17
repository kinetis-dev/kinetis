<?php

declare(strict_types=1);

namespace App\Entities;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;
use LogicException;

/**
 * One row of ping_messages: the ping a scenario created, and whether it
 * has been ponged yet.
 *
 * Both timestamps are UTC, which is the only thing a DATETIME(6) column
 * holds — nothing converts a zone on the way in or out, so the instant
 * this records is the instant the row keeps.
 */
#[Entity(table: 'ping_messages')]
final class Ping
{
    #[Id(generated: true)]
    private ?int $id = null;

    private string $status = 'pending';

    private DateTimeImmutable $createdAt;

    private ?DateTimeImmutable $pongedAt = null;

    public function __construct(private string $scenario)
    {
        $this->createdAt = self::now();
    }

    /**
     * MySQL assigns the key, so it exists only once the insert has
     * committed. Asking earlier is a bug in the caller, not a value to
     * hand back as null for the next layer to guess about.
     */
    public function id(): int
    {
        return $this->id ?? throw new LogicException('A ping has no id until its insert has been flushed.');
    }

    public function pong(): void
    {
        $this->status = 'ponged';
        $this->pongedAt = self::now();
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
