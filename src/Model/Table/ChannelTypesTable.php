<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Model\Table;

use Cake\ORM\Table;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;

/**
 * A type table of system rows, found by `value`.
 */
class ChannelTypesTable extends Table
{
    /**
     * @var array<string, int>
     */
    private array $ids = [];

    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_messaging_client_channel_types');
        $this->setDisplayField('label');
        $this->setPrimaryKey('id');
        $this->setEntityClass(ChannelType::class);
    }

    /**
     * @param string $value One of the `ChannelType` value constants.
     * @return int
     */
    public function idFor(string $value): int
    {
        if (!isset($this->ids[$value])) {
            /** @var \RockSoftware\MessagingClient\Model\Entity\ChannelType $row */
            $row = $this->find()->where(['value' => $value])->firstOrFail();
            $this->ids[$value] = $row->id;
        }

        return $this->ids[$value];
    }

    /**
     * @param string $value One of the `ChannelType` value constants.
     * @return \RockSoftware\MessagingClient\Model\Entity\ChannelType
     */
    public function forValue(string $value): ChannelType
    {
        return $this->get($this->idFor($value));
    }
}
