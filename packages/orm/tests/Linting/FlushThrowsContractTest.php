<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Linting;

use PHPStan\Rules\Exceptions\CatchWithUnthrownExceptionRule;
use PHPStan\Rules\Exceptions\DefaultExceptionTypeResolver;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * `EntityManager::flush()` rethrows every persistence failure it meets
 * before COMMIT, so its `@throws` names `SqlException`. Without that, the
 * unique-conflict recovery every race-safe caller writes is dead code to
 * PHPStan.
 *
 * @extends RuleTestCase<CatchWithUnthrownExceptionRule>
 */
final class FlushThrowsContractTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new CatchWithUnthrownExceptionRule(
            self::getContainer()->getByType(DefaultExceptionTypeResolver::class),
            true,
        );
    }

    public function test_flush_admits_a_query_exception_and_nothing_wider(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/FlushFailureRecovery.php'], [
            [
                'Dead catch - JsonException is never thrown in the try block.',
                26,
            ],
        ]);
    }
}
