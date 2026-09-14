<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Column;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

/** An explicit #[Id] wins over a property named id; a schema-qualified table. */
#[Entity(table: 'reporting.accounts')]
final class Account
{
    #[Id]
    #[Column(name: 'account_uuid')]
    public string $uuid;

    public ?int $id;

    #[Column(name: 'email_address')]
    public string $email;
}
