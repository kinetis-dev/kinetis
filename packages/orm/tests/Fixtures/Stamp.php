<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

/** A join-table target whose identifier is a string the application assigns. */
#[Entity(table: 'stamps')]
final class Stamp
{
    #[Id]
    public string $code;

    public string $issuer;

    public static function coded(string $code, string $issuer): self
    {
        $stamp = new self();
        $stamp->code = $code;
        $stamp->issuer = $issuer;

        return $stamp;
    }
}
