<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests\Fixtures\OrmProject\Entities;

/** Carries an attribute, so discovery reflects it, but not #[Entity]. */
#[\AllowDynamicProperties]
final class Unmapped
{
    public string $name = '';
}
