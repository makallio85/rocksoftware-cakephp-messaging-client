<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Model\Entity;

use Cake\ORM\Entity;

/**
 * The sending switch of one channel in this application.
 *
 * @property int $id
 * @property int $channel_type_id
 * @property bool $is_sending_enabled
 * @property string|null $enabled_for_url The application's full base URL when it was switched on.
 * @property string|null $changed_by Who switched it last, as the settings page could name them.
 * @property \Cake\I18n\DateTime|null $changed_at
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \RockSoftware\MessagingClient\Model\Entity\ChannelType|null $channel_type
 */
class ChannelSetting extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => false,
    ];
}
