<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

use Kinetis\Container\RequestScope;

/**
 * The request-scoped dependency written as optional. Resolved through
 * AppScope it is a wiring defect, and the default never stands in for it.
 */
final class WithOptionalRequestScopeDependency
{
    public function __construct(
        public readonly ?RequestScope $scope = null,
    ) {}
}
