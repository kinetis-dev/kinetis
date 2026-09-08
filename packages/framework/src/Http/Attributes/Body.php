<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;

/**
 * Marks a controller-method parameter as bound to the decoded request
 * body. The parameter's type is the DTO class Dispatcher hydrates and
 * validates before the controller ever runs.
 *
 * The supported media types are `application/json`, any
 * `application/*+json` subtype, `application/x-www-form-urlencoded` and
 * `multipart/form-data`. A nonblank body sent under any other — or under
 * no `Content-Type` at all — is refused with a 415 before hydration; a
 * blank body still hydrates an all-optional DTO. A route that has to
 * accept arbitrary bytes takes a `ServerRequestInterface` parameter
 * instead of this attribute.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Body {}
