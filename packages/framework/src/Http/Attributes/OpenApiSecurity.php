<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;
use Kinetis\OpenApi\SecurityDescriberInterface;

/**
 * States this operation's published OpenAPI security, instead of the one
 * inferred from the middleware around it.
 *
 * Each argument is a class implementing {@see SecurityDescriberInterface};
 * listing several requires all of them, as AND. Alternatives (OR) belong
 * inside one provider's {@see \Kinetis\OpenApi\SecurityDescription}. No
 * argument publishes `security: []`, which removes the root security an
 * operation otherwise inherits.
 *
 * On a method it governs that operation; on a controller class, every
 * operation of that class, and a method declaration replaces the class
 * one. Both are read from the controller the route was registered on,
 * never from a parent — see {@see \Kinetis\Reflection\AttributeScope}.
 *
 * The declaration is the whole published security of the operation: it
 * does not combine with global or route inference.
 *
 * It changes the document alone. Every middleware still runs exactly as
 * declared, so a no-argument declaration documents a route whose
 * pipeline already admits an unauthenticated request; it does not make a
 * guarded one reachable. Use it where inference cannot see the truth: a
 * controller that authenticates in its own body, and middleware that
 * describes a requirement it does not impose on this route.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class OpenApiSecurity
{
    /** @var list<class-string<SecurityDescriberInterface>> */
    public array $providers;

    /**
     * @param class-string<SecurityDescriberInterface> ...$providers
     */
    public function __construct(string ...$providers)
    {
        $this->providers = array_values($providers);
    }
}
