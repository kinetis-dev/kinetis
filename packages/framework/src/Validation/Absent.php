<?php

declare(strict_types=1);

namespace Kinetis\Validation;

/**
 * The presence marker an update DTO's field carries when the input did
 * not mention it at all.
 *
 * A DTO that must tell "the client left this alone" from "the client
 * explicitly cleared it" needs a third state beyond a value and `null`,
 * and PHP's own default machinery has no spelling for one:
 * `?string $bio = null` binds `null` for both. Declaring
 * `string|null|Absent $bio = Absent::Value` gives each answer its own
 * value — the field is `Absent::Value` when the member was omitted,
 * `null` when it was sent as `null`, and the string when one was sent.
 *
 * {@see Hydrator} accepts this enum in exactly two union forms on a DTO
 * constructor parameter — `T|Absent` and `T|null|Absent`, in either case
 * defaulted to exactly `Absent::Value` — and nowhere else. It is not a
 * transport-method parameter feature: a controller's `#[Query]`/path
 * parameter and an MCP tool method parameter still reject every union.
 *
 * No input can produce it. The marker is bound only from the parameter's
 * own default, so a client cannot claim a field was omitted by sending
 * something; an `Absent` instance appearing in hydrated data is rejected
 * as the wrong type for the field, not read as omission. It is likewise
 * absent from generated schemas, which describe `T` (and `null` where
 * declared) and mark the member optional because it has a default.
 *
 * Create and update remain separate DTO classes. The HTTP method does
 * not select behavior, and one class does not change shape per verb.
 */
enum Absent
{
    case Value;
}
