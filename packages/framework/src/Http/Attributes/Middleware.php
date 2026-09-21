<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;

/**
 * Declares a PSR-15 middleware class that wraps this route (method-level)
 * or every route on the controller (class-level). Repeatable and
 * stackable across both levels — class-level middleware runs first
 * (outermost), method-level middleware runs closer to the controller, in
 * declaration order within each level. Router::register() discovers these
 * the same way it discovers #[Get]/#[Post]/..., via one more
 * getAttributes() call inside its existing reflection loop.
 *
 * A value prefixed with `@` names a middleware *group* instead of a
 * single class — `#[Middleware('@admin')]` runs every class carrying
 * #[AsMiddlewareGroup('admin')], in that group's own priority order,
 * expanded in place where the reference sits. Group names are validated
 * when Kernel is constructed: a reference to a group nothing declares
 * fails at startup rather than at request time.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Middleware
{
    /**
     * Marks $middlewareClass as a group name rather than a class-string.
     * A single character, so no real class-string can collide with it —
     * `@` is not legal in a PHP identifier.
     */
    public const string GROUP_PREFIX = '@';

    /**
     * @param class-string<\Psr\Http\Server\MiddlewareInterface>|string $middlewareClass a middleware class, or `@name` referencing a group
     */
    public function __construct(
        public string $middlewareClass,
    ) {}

    /**
     * Expands a declared middleware list, replacing each `@name`
     * reference with that group's own members in place — so a group runs
     * exactly where the reference sits, and one entry being a whole
     * group leaves the declaration order around it intact.
     *
     * Owned here because request dispatch and OpenAPI generation must
     * read one pipeline: a document expanding groups differently would
     * describe middleware the request does not run.
     *
     * An unknown group name expands to nothing; `Kernel` rejects one
     * when it is constructed, so a running application never reaches
     * that.
     *
     * @param list<class-string|string> $references
     * @param array<string, list<class-string>> $groups
     * @return list<class-string>
     */
    public static function expandGroups(array $references, array $groups): array
    {
        $expanded = [];

        foreach ($references as $reference) {
            if (!str_starts_with($reference, self::GROUP_PREFIX)) {
                /** @var class-string $reference */
                $expanded[] = $reference;

                continue;
            }

            foreach ($groups[substr($reference, strlen(self::GROUP_PREFIX))] ?? [] as $middlewareClass) {
                $expanded[] = $middlewareClass;
            }
        }

        return $expanded;
    }
}
