<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;

/**
 * Marks a controller-method parameter as bound to the decoded request
 * body. The parameter's type is the DTO class Dispatcher hydrates and
 * validates before the controller ever runs.
 *
 * `#[Body]` hydrates the DTO from the whole document. `#[Body('user')]`
 * hydrates it from the document's one top-level `user` member instead;
 * a root is a single member name, never a path. A method declares at
 * most one `#[Body]` parameter.
 *
 * The supported media types are `application/json`, any
 * `application/*+json` subtype, `application/x-www-form-urlencoded` and
 * `multipart/form-data`. A nonblank body sent under any other — or under
 * no `Content-Type` at all — is refused with a 415 before hydration; a
 * blank body is a document with no members. A route that has to
 * accept arbitrary bytes takes a `ServerRequestInterface` parameter
 * instead of this attribute.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Body
{
    public function __construct(
        private ?string $root = null,
    ) {}

    public function root(): ?string
    {
        return $this->root;
    }
}
