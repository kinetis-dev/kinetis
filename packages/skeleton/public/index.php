<?php

declare(strict_types=1);

use Kinetis\Runtime\HttpStartup;

require dirname(__DIR__) . '/vendor/autoload.php';

// The whole application startup program lives in the framework — see
// HttpStartup's own docblock for the order it runs in and why. This file
// only says where the application is.
HttpStartup::run(__DIR__);
