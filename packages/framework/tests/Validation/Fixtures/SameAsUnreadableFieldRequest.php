<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ObjectConstraints\SameAs;

/**
 * `confirmation` is a constructor field the input can supply, but the
 * class keeps no property for it — so the rule has a name it was told
 * about and nothing to read it from. That is a mistake in the attribute
 * or the class, not in the request.
 *
 * Both fields default, so a request may omit either or both of them: a
 * declaration this broken must not be able to hide behind an input that
 * skips the comparison.
 */
#[SameAs('confirmation', 'email')]
final class SameAsUnreadableFieldRequest
{
    public string $email;

    public function __construct(string $email = 'owner@example.test', string $confirmation = 'owner@example.test')
    {
        $this->email = $email;
    }
}
