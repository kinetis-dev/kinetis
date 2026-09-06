<?php

declare(strict_types=1);

namespace Kinetis\Mailer;

/**
 * The one sender that is not a Fiber: code running on the main stack,
 * where `Fiber::getCurrent()` answers null. {@see SafeMailer} records
 * who holds its lock as either the running Fiber or this, so that a
 * send started from the main stack and a nested send started from the
 * same place compare equal, the way two calls inside one Fiber do.
 *
 * @internal to kinetis/mailer
 */
enum RootSender
{
    case Instance;
}
