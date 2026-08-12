<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Responses;

use Kinetis\Http\Responses\RedirectResponse;
use PHPUnit\Framework\TestCase;

final class RedirectResponseTest extends TestCase
{
    public function test_defaults_to_a_302_with_a_location_header(): void
    {
        $response = RedirectResponse::to('/new-page');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/new-page', $response->getHeaderLine('Location'));
    }

    public function test_accepts_a_custom_status(): void
    {
        $response = RedirectResponse::to('/new-page', 301);

        self::assertSame(301, $response->getStatusCode());
    }
}
