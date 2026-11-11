<?php

declare(strict_types=1);

namespace Kinetis\Console;

/**
 * The foreground colours {@see Output} can apply to one whole line. Each
 * maps to a single ANSI SGR code; there is no bold, background or
 * nesting.
 */
enum Color
{
    case Green;
    case Yellow;
    case Red;
    case Cyan;
    case Gray;
}
