<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Linting\Fixtures;

use JsonException;
use Kinetis\Orm\EntityManager;
use Kinetis\Persistence\Exception\QueryException;

final class FlushFailureRecovery
{
    /** @throws QueryException */
    public function recover(EntityManager $manager): bool
    {
        try {
            $manager->flush();

            return true;
        } catch (QueryException $conflict) {
            if (!$conflict->isUniqueViolation()) {
                throw $conflict;
            }

            return false;
        } catch (JsonException) {
            return false;
        }
    }
}
