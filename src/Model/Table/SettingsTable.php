<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;
use InvalidArgumentException;
use RockSoftware\MessagingClient\Gateway\GatewayClient;
use RockSoftware\MessagingClient\Model\Entity\Setting;

/**
 * The one settings row.
 */
class SettingsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_messaging_client_settings');
        $this->setPrimaryKey('id');
        $this->setEntityClass(Setting::class);
        $this->addBehavior('Timestamp');
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->allowEmptyString('gateway_url')
            ->add('gateway_url', 'httpsHost', [
                'rule' => function ($value): string|bool {
                    try {
                        GatewayClient::apiRoot((string)$value);
                    } catch (InvalidArgumentException $exception) {
                        return $exception->getMessage();
                    }

                    return true;
                },
            ]);
    }

    /**
     * The row, or null before anything is saved.
     *
     * @return \RockSoftware\MessagingClient\Model\Entity\Setting|null
     */
    public function current(): ?Setting
    {
        /** @var \RockSoftware\MessagingClient\Model\Entity\Setting|null */
        return $this->find()->orderBy(['id' => 'ASC'])->first();
    }

    /**
     * The row, or a new empty one to fill.
     *
     * @return \RockSoftware\MessagingClient\Model\Entity\Setting
     */
    public function currentOrNew(): Setting
    {
        return $this->current() ?? $this->newEmptyEntity();
    }
}
