<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Model\Table;

use Cake\I18n\DateTime;
use Cake\ORM\Table;
use RockSoftware\MessagingClient\Model\Entity\ChannelSetting;

/**
 * The sending switches, one row per channel at most.
 *
 * @property \RockSoftware\MessagingClient\Model\Table\ChannelTypesTable $ChannelTypes
 */
class ChannelSettingsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_messaging_client_channel_settings');
        $this->setPrimaryKey('id');
        $this->setEntityClass(ChannelSetting::class);
        $this->addBehavior('Timestamp');
        $this->belongsTo('ChannelTypes', [
            'className' => ChannelTypesTable::class,
            'foreignKey' => 'channel_type_id',
        ]);
    }

    /**
     * The switch of a channel, or null while it has never been switched.
     *
     * @param string $channel `ChannelType::*`.
     * @return \RockSoftware\MessagingClient\Model\Entity\ChannelSetting|null
     */
    public function forChannel(string $channel): ?ChannelSetting
    {
        /** @var \RockSoftware\MessagingClient\Model\Entity\ChannelSetting|null */
        return $this->find()
            ->where(['channel_type_id' => $this->ChannelTypes->idFor($channel)])
            ->first();
    }

    /**
     * Switches a channel on (bound to `$url`) or off.
     *
     * @param string $channel `ChannelType::*`.
     * @param bool $isOn On or off.
     * @param string|null $url The application's full base URL; kept only while on.
     * @param string|null $changedBy Who switched it.
     * @return \RockSoftware\MessagingClient\Model\Entity\ChannelSetting
     */
    public function switchSending(string $channel, bool $isOn, ?string $url, ?string $changedBy): ChannelSetting
    {
        $setting = $this->forChannel($channel) ?? $this->newEmptyEntity();
        $setting->patch([
            'channel_type_id' => $this->ChannelTypes->idFor($channel),
            'is_sending_enabled' => $isOn,
            'enabled_for_url' => $isOn ? $url : null,
            'changed_by' => $changedBy === null ? null : mb_substr($changedBy, 0, 255),
            'changed_at' => DateTime::now(),
        ], ['guard' => false]);
        $this->saveOrFail($setting);

        return $setting;
    }
}
