<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Model\Entity;

use Cake\ORM\Entity;

/**
 * One message the application sent or tried to send through the gateway,
 * recorded before the first attempt. One row per `external_ref`.
 *
 * @property int $id
 * @property string $external_ref
 * @property int $channel_type_id
 * @property int $outbound_message_status_type_id
 * @property string|null $sender_code The sender it was sent as; null for the gateway's default.
 * @property string $recipient
 * @property string|null $subject
 * @property string|null $gateway_message_uuid
 * @property bool $is_test True when the gateway traps it (a test token or connection): it reaches no one.
 * @property string|null $error
 * @property int $attempts
 * @property array<string, mixed>|null $context
 * @property array<string, mixed>|null $payload The request as sent to the gateway, kept for resending.
 * @property \Cake\I18n\DateTime|null $expires_at
 * @property \Cake\I18n\DateTime|null $next_attempt_at
 * @property \Cake\I18n\DateTime|null $last_attempted_at
 * @property \Cake\I18n\DateTime|null $next_status_check_at When the worker next asks the gateway about it.
 * @property \Cake\I18n\DateTime|null $status_checked_at
 * @property \Cake\I18n\DateTime|null $delivered_at
 * @property \Cake\I18n\DateTime|null $final_status_at When the gateway reported its last status.
 * @property int|null $resent_from_id The message this one resends.
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \RockSoftware\MessagingClient\Model\Entity\ChannelType|null $channel_type
 * @property \RockSoftware\MessagingClient\Model\Entity\OutboundMessageStatusType|null $outbound_message_status_type
 */
class OutboundMessage extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => false,
    ];

    /**
     * @var list<string>
     */
    protected array $_hidden = ['payload'];

    /**
     * The status value (`OutboundMessageStatusType::*`), when the status type is loaded.
     *
     * @return string|null
     */
    public function statusValue(): ?string
    {
        return $this->outbound_message_status_type?->value;
    }

    /**
     * The channel value (`ChannelType::*`), when the channel type is loaded.
     *
     * @return string|null
     */
    public function channelValue(): ?string
    {
        return $this->channel_type?->value;
    }

    /**
     * Whether it is still waiting for the gateway to accept it.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->statusValue() === OutboundMessageStatusType::PENDING;
    }

    /**
     * Whether the gateway accepted it.
     *
     * @return bool
     */
    public function isAccepted(): bool
    {
        return $this->statusValue() === OutboundMessageStatusType::ACCEPTED;
    }
}
