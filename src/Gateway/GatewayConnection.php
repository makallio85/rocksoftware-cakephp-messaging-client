<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Gateway;

/**
 * One identity the token may send as, from `GET /connections`.
 */
final class GatewayConnection
{
    /**
     * @param string $code What a send names as `account`.
     * @param string $channel `ChannelType::*`, or a channel this plugin does not send on.
     * @param string $name The connection's name.
     * @param string $identity The address messages go out from: the mailbox or the sending number.
     * @param string $displayName The sender's display name.
     * @param bool $isTest True when everything sent from it is trapped.
     * @param bool $isOutboundEnabled False while sending from it is switched off.
     * @param bool $isOwned True when replies to it come to this application.
     * @param bool $isInboundEnabled True when anything arriving on it is read.
     */
    public function __construct(
        public readonly string $code,
        public readonly string $channel,
        public readonly string $name,
        public readonly string $identity,
        public readonly string $displayName,
        public readonly bool $isTest,
        public readonly bool $isOutboundEnabled,
        public readonly bool $isOwned,
        public readonly bool $isInboundEnabled,
    ) {
    }
}
