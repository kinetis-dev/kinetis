<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Dsn;

/**
 * A validated `failover(...)` or `roundrobin(...)` group.
 *
 * A single-member group is accepted: `failover(smtps://u:p@mail.test)`
 * behaves exactly as the leaf alone does, so refusing it would buy
 * nothing while breaking a configuration that grows a second member
 * later.
 *
 * `$retryPeriod` is the composite-only `retry_period` option, in seconds,
 * defaulting to Symfony's own 60. It marks a child dead for *later*
 * selections after that child fails; it is neither a backoff applied
 * inside the current call nor a delivery guarantee. See
 * {@see \Kinetis\Mailer\SafeMailer} for what a composite means for
 * duplicate delivery.
 *
 * @internal to kinetis/mailer
 */
final readonly class CompositeDsn implements DsnNode
{
    /**
     * @param non-empty-list<DsnNode> $members
     * @param int<1, 86400>           $retryPeriod
     */
    public function __construct(
        public CompositeKind $kind,
        public array $members,
        public int $retryPeriod,
    ) {}
}
