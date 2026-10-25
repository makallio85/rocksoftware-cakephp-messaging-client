<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Gateway;

/**
 * The gateway's `GET /suppressions/check`: whether an address may be messaged.
 */
final class SuppressionCheck
{
    /**
     * @param string $channel The channel asked about.
     * @param string $identity The address in the gateway's normalised form.
     * @param bool $isSuppressed True when the address is blocked.
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $identity,
        public readonly bool $isSuppressed,
    ) {
    }
}
