<?php

declare(strict_types=1);

/**
 * One-shot generator for bench/many_controllers.php's fixtures — NOT part
 * of the framework's own autoloading (no composer.json changes), so it's
 * fully disposable. Produces N real, separate controller+DTO file pairs
 * under bench/fixtures/manyControllers/, each a single-method controller
 * taking a single #[Body] DTO — the exact "single method, single DTO per
 * controller" pattern being tested, so real per-file autoloading actually
 * has N distinct files to load, not one file reused N times.
 */
const COUNT = 150;

$dir = __DIR__ . '/fixtures/manyControllers';

if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

for ($i = 1; $i <= COUNT; $i++) {
    $n = sprintf('%03d', $i);

    // Method body padded to ~2000 lines via repeated no-op statements, to
    // test the claim directly: does a large method body make *reflection*
    // slower, or only class-loading (which happens either way once the
    // class is actually used)?
    $padding = str_repeat("        \$noop = 1 + 1; // padding line to reach ~2000 lines\n", 1990);

    $controller = <<<PHP
<?php

declare(strict_types=1);

namespace Kinetis\Bench\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;

final readonly class Controller{$n}
{
    #[Post('/bench/{$n}')]
    public function handle(#[Body] Dto{$n} \$data): array
    {
{$padding}
        return ['value' => \$data->value];
    }
}

PHP;

    $dto = <<<PHP
<?php

declare(strict_types=1);

namespace Kinetis\Bench\Fixtures;

use Kinetis\Validation\Constraints\MinLength;

final readonly class Dto{$n}
{
    public function __construct(
        #[MinLength(1)]
        public string \$value,
    ) {}
}

PHP;

    file_put_contents("{$dir}/Controller{$n}.php", $controller);
    file_put_contents("{$dir}/Dto{$n}.php", $dto);
}

echo "Generated " . COUNT . " controller+DTO file pairs (~2000 lines each controller) in {$dir}\n";
