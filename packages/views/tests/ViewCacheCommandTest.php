<?php

declare(strict_types=1);

namespace Kinetis\Views\Tests;

use Kinetis\Views\Console\ClearCommand;
use Kinetis\Views\Console\WarmCommand;
use Kinetis\Views\Tests\Fixtures\CommandOutput;
use Kinetis\Views\ViewEngineInterface;
use Kinetis\Views\ViewName;
use Kinetis\Views\Views;
use PHPUnit\Framework\TestCase;

final class ViewCacheCommandTest extends TestCase
{
    public function test_commands_delegate_and_report_truthful_counts(): void
    {
        $views = new Views(new class implements ViewEngineInterface {
            public function render(ViewName $view, array $data): string
            {
                return '';
            }

            public function warmCache(): int
            {
                return 4;
            }

            public function clearCache(): int
            {
                return 5;
            }
        });
        $warmOutput = new CommandOutput();
        $clearOutput = new CommandOutput();

        self::assertSame(0, (new WarmCommand($views, $warmOutput->output))->run());
        self::assertSame(0, (new ClearCommand($views, $clearOutput->output))->run());
        self::assertSame("Warmed 4 view template(s).\n", $warmOutput->takeStdout());
        self::assertSame("Removed 5 cached view file(s).\n", $clearOutput->takeStdout());
        self::assertSame('', $warmOutput->takeStderr());
        self::assertSame('', $clearOutput->takeStderr());
    }
}
