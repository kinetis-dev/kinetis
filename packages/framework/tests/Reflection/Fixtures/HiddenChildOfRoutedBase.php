<?php

declare(strict_types=1);

namespace Kinetis\Tests\Reflection\Fixtures;

use Kinetis\Http\Attributes\Hidden;

/**
 * #[Hidden] on the child, the routed method declared by the parent — the
 * pair that used to document the route regardless, because the attribute
 * was read from the declaring class.
 */
#[Hidden]
final class HiddenChildOfRoutedBase extends AbstractRouted {}
