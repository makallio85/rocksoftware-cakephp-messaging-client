<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Model\Table;

use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use InvalidArgumentException;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;
use RockSoftware\MessagingClient\Model\Entity\Sender;

/**
 * The senders: the gateway connections the application may send as.
 *
 * At most one default per channel, kept here (`setDefault()`), never by the
 * database: switching the default is one transaction.
 *
 * @property \RockSoftware\MessagingClient\Model\Table\ChannelTypesTable $ChannelTypes
 */
class SendersTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_messaging_client_senders');
        $this->setPrimaryKey('id');
        $this->setDisplayField('code');
        $this->setEntityClass(Sender::class);
        $this->addBehavior('Timestamp');
        $this->belongsTo('ChannelTypes', [
            'className' => ChannelTypesTable::class,
            'foreignKey' => 'channel_type_id',
        ]);
    }

    /**
     * Every sender, by channel (`ChannelType::*`), defaults first, then by code.
     *
     * @return array<string, list<\RockSoftware\MessagingClient\Model\Entity\Sender>>
     */
    public function byChannel(): array
    {
        $byChannel = array_fill_keys(ChannelType::VALUES, []);
        $senders = $this->find()
            ->contain(['ChannelTypes'])
            ->orderBy(['is_default' => 'DESC', 'code' => 'ASC'])
            ->all();
        foreach ($senders as $sender) {
            /** @var \RockSoftware\MessagingClient\Model\Entity\Sender $sender */
            $byChannel[(string)$sender->channel_type?->value][] = $sender;
        }

        return $byChannel;
    }

    /**
     * The sender with a code, or null.
     *
     * @param string $code A connection code.
     * @return \RockSoftware\MessagingClient\Model\Entity\Sender|null
     */
    public function findByCode(string $code): ?Sender
    {
        /** @var \RockSoftware\MessagingClient\Model\Entity\Sender|null */
        return $this->find()->contain(['ChannelTypes'])->where(['code' => $code])->first();
    }

    /**
     * The sender a send on `$channel` that names none goes out as: the
     * channel's default, when it is usable; else null (the gateway's default).
     *
     * @param string $channel `ChannelType::*`.
     * @return \RockSoftware\MessagingClient\Model\Entity\Sender|null
     */
    public function defaultFor(string $channel): ?Sender
    {
        /** @var \RockSoftware\MessagingClient\Model\Entity\Sender|null */
        return $this->find()
            ->where([
                'channel_type_id' => $this->ChannelTypes->idFor($channel),
                'is_default' => true,
                'is_enabled' => true,
                'is_available' => true,
            ])
            ->first();
    }

    /**
     * Makes one sender its channel's default (and enables it); the channel's
     * other senders stop being the default.
     *
     * @param string $code A sender's code.
     * @return \RockSoftware\MessagingClient\Model\Entity\Sender
     * @throws \InvalidArgumentException When there is no such sender.
     */
    public function setDefault(string $code): Sender
    {
        $sender = $this->findByCode($code) ?? throw new InvalidArgumentException(
            sprintf('There is no sender "%s".', $code),
        );
        $this->getConnection()->transactional(function () use ($sender): void {
            $this->updateAll(
                ['is_default' => false],
                ['channel_type_id' => $sender->channel_type_id, 'id !=' => $sender->id],
            );
            $sender->patch(['is_default' => true, 'is_enabled' => true], ['guard' => false]);
            $this->saveOrFail($sender);
        });

        return $sender;
    }

    /**
     * A channel with no default: its sends name no sender (the gateway's default).
     *
     * @param string $channel `ChannelType::*`.
     * @return void
     */
    public function clearDefault(string $channel): void
    {
        $this->updateAll(['is_default' => false], ['channel_type_id' => $this->ChannelTypes->idFor($channel)]);
    }

    /**
     * Enables or disables a sender. A disabled sender is no channel's default.
     *
     * @param string $code A sender's code.
     * @param bool $isEnabled Enabled or not.
     * @return \RockSoftware\MessagingClient\Model\Entity\Sender
     * @throws \InvalidArgumentException When there is no such sender.
     */
    public function setEnabled(string $code, bool $isEnabled): Sender
    {
        $sender = $this->findByCode($code) ?? throw new InvalidArgumentException(
            sprintf('There is no sender "%s".', $code),
        );
        $sender->patch(
            ['is_enabled' => $isEnabled, 'is_default' => $isEnabled && $sender->is_default],
            ['guard' => false],
        );
        $this->saveOrFail($sender);

        return $sender;
    }

    /**
     * Brings the senders in line with the gateway's `GET /connections`: new
     * connections are added enabled, known ones get the gateway's current
     * details, and ones the gateway no longer lists are kept, marked
     * unavailable, so a configured sender never silently vanishes.
     * Connections on channels the plugin does not know are left out.
     *
     * @param list<\RockSoftware\MessagingClient\Gateway\GatewayConnection> $connections The gateway's list.
     * @return void
     */
    public function refresh(array $connections): void
    {
        $now = DateTime::now();
        $listed = [];
        $this->getConnection()->transactional(function () use ($connections, $now, &$listed): void {
            foreach ($connections as $connection) {
                if (!in_array($connection->channel, ChannelType::VALUES, true)) {
                    Log::info(sprintf(
                        'Messaging gateway connection %s is on channel "%s", '
                        . 'which this plugin does not send on; left out.',
                        $connection->code,
                        $connection->channel,
                    ));
                    continue;
                }
                $listed[] = $connection->code;
                $sender = $this->findByCode($connection->code) ?? $this->newEmptyEntity();
                $sender->patch([
                    'channel_type_id' => $this->ChannelTypes->idFor($connection->channel),
                    'code' => $connection->code,
                    'identity' => $connection->identity === '' ? null : $connection->identity,
                    'display_name' => $connection->displayName === '' ? null : $connection->displayName,
                    'is_available' => true,
                    'is_test' => $connection->isTest,
                    'is_owned' => $connection->isOwned,
                    'is_outbound_enabled' => $connection->isOutboundEnabled,
                    'refreshed_at' => $now,
                ], ['guard' => false]);
                $this->saveOrFail($sender);
            }
            $missing = $listed === [] ? [] : ['code NOT IN' => $listed];
            $this->updateAll(['is_available' => false, 'refreshed_at' => $now], $missing);
        });
    }

    /**
     * Takes over a sender an application's data migration left in the
     * settings' `email_account` / `sms_account` (the way it hands one over):
     * it becomes the channel's default, and the column is emptied.
     *
     * @return void
     */
    public function adoptLegacyAccounts(): void
    {
        $settings = TableRegistry::getTableLocator()->get('RockSoftware/MessagingClient.Settings');
        /** @var \RockSoftware\MessagingClient\Model\Entity\Setting|null $setting */
        $setting = $settings->find()
            ->where(['OR' => ['email_account IS NOT' => null, 'sms_account IS NOT' => null]])
            ->orderBy(['id' => 'ASC'])
            ->first();
        if ($setting === null) {
            return;
        }
        foreach ([ChannelType::EMAIL => 'email_account', ChannelType::SMS => 'sms_account'] as $channel => $column) {
            $code = trim((string)$setting->get($column));
            if ($code === '') {
                continue;
            }
            $sender = $this->findByCode($code) ?? $this->newEmptyEntity();
            if ($sender->isNew()) {
                $sender->patch([
                    'channel_type_id' => $this->ChannelTypes->idFor($channel),
                    'code' => $code,
                    'is_enabled' => true,
                ], ['guard' => false]);
                $this->saveOrFail($sender);
            }
            $this->setDefault($code);
        }
        $settings->updateAll(['email_account' => null, 'sms_account' => null], ['id' => $setting->id]);
    }
}
