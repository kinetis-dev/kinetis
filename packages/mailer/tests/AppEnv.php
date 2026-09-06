<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

/**
 * The `APP_ENV` values these tests set, so a case reads as the
 * environment it is exercising rather than as a bare string.
 */
enum AppEnv: string
{
    case Development = 'development';
    case Production = 'production';
}
