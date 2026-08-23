<?php

declare(strict_types=1);

namespace Kinetis\Linting;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * "No global state bleed" in a persistent worker can't be enforced by the
 * language itself — PHP doesn't sandbox memory per request — so it's
 * enforced by convention (a fresh RequestScope per request) plus this
 * rule catching the one thing a fresh container per request *can't*
 * catch: a `static` property, which survives across every request a
 * worker handles for as long as it stays alive, silently reintroducing
 * the exact cross-request state bleed the container-per-request design
 * exists to prevent.
 *
 * Ships under the main autoload (not a dev-only tool) because it's meant
 * to run against a *consumer's* application code — add it to their own
 * `phpstan.neon` `rules:` list, not just this repo's own toolchain.
 * `autoload-dev` can't serve that: Composer never installs a dependency's
 * own autoload-dev section, so a consumer's phpstan.neon could never
 * reach the class at all.
 *
 * The PHPStan/php-parser imports above are require-dev-only packages —
 * deliberate, not an undeclared dependency: this class is only ever
 * *loaded* by a PHPStan run, and a PHPStan run has both packages present
 * by definition (they're PHPStan itself and its own parser). Nothing at
 * runtime references this class, so a production install where neither
 * package exists never attempts the autoload.
 *
 * @implements Rule<Property>
 */
final class NoStaticPropertiesRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return Property::class;
    }

    /**
     * @param Property $node
     * @return list<IdentifierRuleError>
     */
    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->isStatic()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Static properties hold state across every request a persistent worker handles until it '
                . 'restarts — exactly the cross-request state bleed a fresh RequestScope per request exists '
                . 'to prevent. Use AppScope for state that should genuinely persist for the worker\'s lifetime, '
                . 'or RequestScope for state scoped to one request.',
            )
                ->identifier('kinetis.noStaticProperties')
                ->build(),
        ];
    }
}
