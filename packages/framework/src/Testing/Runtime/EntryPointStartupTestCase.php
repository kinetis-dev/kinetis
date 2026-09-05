<?php

declare(strict_types=1);

namespace Kinetis\Testing\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * The startup order a `public/index.php` has to hold to, run against
 * the real file each application ships.
 *
 *     final class EntryPointStartupTest extends EntryPointStartupTestCase
 *     {
 *         protected function entryPoint(): string
 *         {
 *             return dirname(__DIR__) . '/public/index.php';
 *         }
 *     }
 *
 * `Kinetis\Container\AppScope` locks its bindings at `boot()`, so an
 * entry point has one window to register anything and one place to read
 * the result: everything it binds goes in before `boot()`, where the
 * bootstrap chain can still replace it, and everything it hands to the
 * runtime adapter comes back out of the booted container, so the adapter
 * bounds and forwards a request by the same `FormLimits` and
 * `TrustedProxies` the Kernel's own middleware enforces. A registration
 * on the far side of `boot()` throws a `ContainerException` while the
 * entry point is still running, so the process serves nothing at all —
 * a failure no unit test of the classes the file wires can see.
 *
 * The assertions read the entry point's own source with the tokenizer
 * and ignore its comments, because starting one needs a SAPI, an
 * autoloader and an adapter loop that never returns.
 */
abstract class EntryPointStartupTestCase extends TestCase
{
    /** Every call that registers something on the application container. */
    private const array REGISTRATION_CALLS = [
        '$app->instance(',
        '$app->bind(',
        '$app->middleware(',
        '$app->openApiMiddleware(',
    ];

    /** The absolute path of the `public/index.php` under test. */
    abstract protected function entryPoint(): string;

    /**
     * The window closes at `boot()`. A registration on the far side of
     * it throws, and it throws at startup, so the process never serves
     * anything.
     */
    final public function test_nothing_is_registered_after_the_container_locks(): void
    {
        $code = $this->code();
        $boot = $this->offsetOf($code, '$app->boot()');

        foreach (self::REGISTRATION_CALLS as $call) {
            $offset = 0;

            while (($at = strpos($code, $call, $offset)) !== false) {
                self::assertLessThan($boot, $at, "{$call} runs after \$app->boot(), which throws");
                $offset = $at + 1;
            }
        }
    }

    /**
     * Both runtime policies are registered ahead of the bootstrap chain,
     * which is what leaves `bootstrap.php` and every package bootstrap
     * able to replace either one: last write before `boot()` wins, and a
     * default registered after them would be the last write instead. An
     * entry point that runs no bootstrap chain has `boot()` as its own
     * deadline.
     */
    final public function test_the_runtime_policies_are_registered_before_the_bootstrap_chain_runs(): void
    {
        $code = $this->code();
        $chain = strpos($code, 'BootSequence::run(');
        $deadline = $chain === false ? $this->offsetOf($code, '$app->boot()') : $chain;

        self::assertLessThan(
            $deadline,
            $this->offsetOf($code, '$app->instance(FormLimits::class'),
            'the form limits are registered past the point a bootstrap could still replace them',
        );
        self::assertLessThan(
            $deadline,
            $this->offsetOf($code, '$app->instance(TrustedProxies::class'),
            'the trusted proxies are registered past the point a bootstrap could still replace them',
        );
    }

    /**
     * What reaches the adapter is what the booted container holds, not a
     * second pair built beside it: a `bootstrap.php` that narrowed either
     * policy would otherwise bind one object for the Kernel's middleware
     * and leave the adapter bounding the same body by another.
     */
    final public function test_the_adapter_is_handed_the_policies_the_booted_container_holds(): void
    {
        $code = $this->code();
        $boot = $this->offsetOf($code, '$app->boot()');
        $detect = $this->offsetOf($code, 'RuntimeDetector::detect(');

        self::assertLessThan($detect, $boot, 'the adapter is detected before the container is booted');

        foreach (['FormLimits', 'TrustedProxies'] as $policy) {
            $read = $this->offsetOf($code, "\$app->get({$policy}::class)");

            self::assertGreaterThan($boot, $read, "the {$policy} handed to the adapter is read before boot() settles it");
            self::assertLessThan($detect, $read, "the {$policy} handed to the adapter is read after it is needed");
            self::assertFalse(
                strpos($code, "{$policy}::fromConfig(", $boot),
                "a second {$policy} is built after boot() instead of the one the container holds",
            );
        }
    }

    /**
     * The entry point's source with every comment removed, so a call
     * spelled out in an example inside one is not read as a call the
     * file makes.
     */
    private function code(): string
    {
        $source = file_get_contents($this->entryPoint());

        if ($source === false) {
            self::fail('the entry point is unreadable: ' . $this->entryPoint());
        }

        $code = '';

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $code .= $token;

                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];
        }

        return $code;
    }

    private function offsetOf(string $code, string $call): int
    {
        $at = strpos($code, $call);

        if ($at === false) {
            self::fail("the entry point never calls {$call}");
        }

        return $at;
    }
}
