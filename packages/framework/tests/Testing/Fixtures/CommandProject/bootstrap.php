<?php

declare(strict_types=1);

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Tests\Testing\Fixtures\CommandProject\Journal;
use Kinetis\Tests\Testing\Fixtures\CommandProject\UnitOfWork;

// The shape kinetis/database-bridge's transaction guard takes: a
// request-scoped binding whose scope disposal closes it.
return static function (AppScope $app, Config $config): void {
    $journal = new Journal();
    $app->instance(Journal::class, $journal);

    $app->onRequestScopeCreated(static function (RequestScope $scope) use ($journal): void {
        $scope->bind(UnitOfWork::class, static function () use ($scope, $journal): UnitOfWork {
            $unit = new UnitOfWork($journal);
            $scope->onDispose($unit->rollBackIfOpen(...));

            return $unit;
        });
    });
};
