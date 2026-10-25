<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Gateway;

/**
 * The gateway's answer to a send it recorded.
 */
final class SendResult
{
    /** Accepted and queued (HTTP 202). */
    public const RESULT_QUEUED = 'queued';

    /** The same `external_ref` was accepted before (HTTP 200); nothing was sent again. */
    public const RESULT_ALREADY_ACCEPTED = 'already_accepted';

    /** The recipient is suppressed (HTTP 409): recorded, never sent, and no retry changes that. */
    public const RESULT_SUPPRESSED = 'suppressed';

    /**
     * @var list<string>
     */
    public const RESULTS = [self::RESULT_QUEUED, self::RESULT_ALREADY_ACCEPTED, self::RESULT_SUPPRESSED];

    /**
     * @param string $result `RESULT_*`.
     * @param \RockSoftware\MessagingClient\Gateway\GatewayMessage $message The message as the gateway holds it.
     * @param list<string> $ignored Fields and recipients the gateway accepted but did not use.
     */
    public function __construct(
        public readonly string $result,
        public readonly GatewayMessage $message,
        public readonly array $ignored,
    ) {
    }

    /**
     * Whether the recipient is blocked, now or when the message was first sent.
     *
     * @return bool
     */
    public function isSuppressed(): bool
    {
        return $this->result === self::RESULT_SUPPRESSED
            || $this->message->status === GatewayMessage::STATUS_SUPPRESSED;
    }
}
