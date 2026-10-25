<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Model\Entity;

use Cake\ORM\Entity;

/**
 * A gateway connection the application may send as on one channel, kept from
 * `GET /connections`. `code` is what a send names as the gateway's `account`.
 *
 * @property int $id
 * @property int $channel_type_id
 * @property string $code
 * @property string|null $identity The address messages go out from.
 * @property string|null $display_name
 * @property bool $is_enabled Whether the application may send as it.
 * @property bool $is_default Whether a send on its channel that names no sender uses it.
 * @property bool $is_available False when the gateway no longer lists it.
 * @property bool $is_test Whether the gateway traps what is sent from it.
 * @property bool $is_owned Whether replies to it come to this application.
 * @property bool $is_outbound_enabled False while the gateway has sending from it switched off.
 * @property \Cake\I18n\DateTime|null $refreshed_at
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \RockSoftware\MessagingClient\Model\Entity\ChannelType|null $channel_type
 */
class Sender extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => false,
    ];

    /**
     * Whether a send may name it now: enabled here and listed by the gateway.
     *
     * @return bool
     */
    public function isUsable(): bool
    {
        return $this->is_enabled && $this->is_available;
    }
}
