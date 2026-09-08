<?php

declare(strict_types=1);

namespace Kinetis\Search;

/**
 * Which of {@see SearchClient}'s five calls is being made.
 *
 * {@see AbstractSearchClient} assembles the parameters both engines take
 * and names the call with one of these; an adapter maps it to its own
 * engine client's method. That mapping is the only part of a call that
 * differs between the two engines.
 */
enum SearchCall
{
    case Index;

    case Get;

    case Delete;

    case Search;

    case Bulk;
}
