<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * The plugin's own tables. Nothing of the application's has to exist first.
 *
 * - `rock_software_messaging_client_settings`: the one connection to the
 *   gateway (address, token encrypted, the sender per channel).
 * - `rock_software_messaging_client_channel_types`: email and SMS, by the
 *   gateway's own names (system rows the plugin binds to by value).
 * - `rock_software_messaging_client_outbound_message_status_types`: where an
 *   outbound message stands (system rows).
 * - `rock_software_messaging_client_outbound_messages`: the delivery log, one
 *   row per `external_ref`. `payload` holds the request while the message may
 *   still be sent, and is emptied once it is settled.
 */
class CreateRockSoftwareMessagingClientTables extends BaseMigration
{
    public bool $autoId = false;

    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('rock_software_messaging_client_settings', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('gateway_url', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('api_token', 'text', ['null' => true, 'default' => null])
            ->addColumn('email_account', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            ->addColumn('sms_account', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->create();

        $this->typeTable('rock_software_messaging_client_channel_types', [
            ['value' => 'email', 'label' => 'Email', 'sort_order' => 10],
            ['value' => 'sms', 'label' => 'SMS', 'sort_order' => 20],
        ]);
        $this->typeTable('rock_software_messaging_client_outbound_message_status_types', [
            ['value' => 'PENDING', 'label' => 'Waiting to be sent', 'sort_order' => 10],
            ['value' => 'ACCEPTED', 'label' => 'Accepted by the gateway', 'sort_order' => 20],
            ['value' => 'SUPPRESSED', 'label' => 'Not sent: recipient blocked', 'sort_order' => 30],
            ['value' => 'FAILED', 'label' => 'Failed', 'sort_order' => 40],
            ['value' => 'NOT_SENT_DELIVERY_OFF', 'label' => 'Not sent: delivery is off', 'sort_order' => 50],
        ]);

        $this->table('rock_software_messaging_client_outbound_messages', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('external_ref', 'string', ['limit' => 191, 'null' => false])
            ->addColumn('channel_type_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('outbound_message_status_type_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('account', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            ->addColumn('recipient', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('subject', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('gateway_message_uuid', 'string', ['limit' => 36, 'null' => true, 'default' => null])
            ->addColumn('is_test', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('error', 'text', ['null' => true, 'default' => null])
            ->addColumn('attempts', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('context', 'text', ['null' => true, 'default' => null])
            ->addColumn('payload', 'text', ['limit' => 16777215, 'null' => true, 'default' => null])
            ->addColumn('expires_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('next_attempt_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('last_attempted_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['external_ref'], ['unique' => true, 'name' => 'rsmc_outbound_messages_external_ref'])
            ->addIndex(
                ['outbound_message_status_type_id', 'next_attempt_at'],
                ['name' => 'rsmc_outbound_messages_status_next_attempt'],
            )
            ->addIndex(['created'], ['name' => 'rsmc_outbound_messages_created'])
            ->addForeignKey('channel_type_id', 'rock_software_messaging_client_channel_types', 'id', [
                'constraint' => 'rsmc_outbound_messages_channel_type',
            ])
            ->addForeignKey(
                'outbound_message_status_type_id',
                'rock_software_messaging_client_outbound_message_status_types',
                'id',
                ['constraint' => 'rsmc_outbound_messages_status_type'],
            )
            ->create();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $tables = [
            'rock_software_messaging_client_outbound_messages',
            'rock_software_messaging_client_outbound_message_status_types',
            'rock_software_messaging_client_channel_types',
            'rock_software_messaging_client_settings',
        ];
        foreach ($tables as $table) {
            $this->table($table)->drop()->save();
        }
    }

    /**
     * @param string $name The table.
     * @param array<array<string, mixed>> $rows Its system rows.
     * @return void
     */
    private function typeTable(string $name, array $rows): void
    {
        $this->table($name, ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('value', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addIndex(['value'], ['unique' => true, 'name' => substr('rsmc_' . substr($name, 31), 0, 58) . '_value'])
            ->create();
        $this->table($name)->insert($rows)->save();
    }
}
