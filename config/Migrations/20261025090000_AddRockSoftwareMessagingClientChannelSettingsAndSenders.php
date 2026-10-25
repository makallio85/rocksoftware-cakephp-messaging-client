<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * - `rock_software_messaging_client_channel_settings`: the sending switch of
 *   each channel, at most one row per channel type. No row, or a row switched
 *   off, means the channel sends nothing; a row switched on counts only at the
 *   address it was switched on at (`enabled_for_url`), so a copy of the
 *   database on another host reads as off. No rows are seeded: every channel
 *   starts off.
 * - `rock_software_messaging_client_senders`: the gateway connections the
 *   application may send as, refreshed from `GET /connections`, each enabled
 *   or not, at most one default per channel. A connection that disappears from
 *   the gateway is kept, `is_available` false.
 * - The sender each channel was set to send as (`email_account`,
 *   `sms_account` in the settings) becomes that channel's default sender. The
 *   two columns stay, emptied, as the way an application's own data migration
 *   hands a sender over (`SendersTable::adoptLegacyAccounts()` takes it).
 * - `outbound_messages.account` becomes `sender_code`.
 */
class AddRockSoftwareMessagingClientChannelSettingsAndSenders extends BaseMigration
{
    public bool $autoId = false;

    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('rock_software_messaging_client_channel_settings', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('channel_type_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('is_sending_enabled', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('enabled_for_url', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('changed_by', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('changed_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['channel_type_id'], ['unique' => true, 'name' => 'rsmc_channel_settings_channel_type'])
            ->addForeignKey('channel_type_id', 'rock_software_messaging_client_channel_types', 'id', [
                'constraint' => 'rsmc_channel_settings_channel_type_fk',
            ])
            ->create();

        $this->table('rock_software_messaging_client_senders', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('channel_type_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('code', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('identity', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('display_name', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('is_enabled', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('is_default', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('is_available', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('is_test', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('is_owned', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('is_outbound_enabled', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('refreshed_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['code'], ['unique' => true, 'name' => 'rsmc_senders_code'])
            ->addIndex(['channel_type_id'], ['name' => 'rsmc_senders_channel_type'])
            ->addForeignKey('channel_type_id', 'rock_software_messaging_client_channel_types', 'id', [
                'constraint' => 'rsmc_senders_channel_type_fk',
            ])
            ->create();

        $this->table('rock_software_messaging_client_outbound_messages')
            ->renameColumn('account', 'sender_code')
            ->update();

        $now = gmdate('Y-m-d H:i:s');
        $channels = [];
        foreach ($this->fetchAll('SELECT id, value FROM rock_software_messaging_client_channel_types') as $row) {
            $channels[$row['value']] = (int)$row['id'];
        }
        $settings = $this->fetchAll(
            'SELECT id, email_account, sms_account FROM rock_software_messaging_client_settings ORDER BY id LIMIT 1',
        );
        $codes = [];
        foreach ($settings as $setting) {
            foreach (['email' => 'email_account', 'sms' => 'sms_account'] as $channel => $column) {
                $code = trim((string)$setting[$column]);
                if ($code === '' || isset($codes[$code]) || !isset($channels[$channel])) {
                    continue;
                }
                $codes[$code] = true;
                $this->table('rock_software_messaging_client_senders')->insert([
                    'channel_type_id' => $channels[$channel],
                    'code' => $code,
                    'is_enabled' => true,
                    'is_default' => true,
                    'created' => $now,
                    'modified' => $now,
                ])->save();
            }
        }
        $this->execute(
            'UPDATE rock_software_messaging_client_settings SET email_account = NULL, sms_account = NULL',
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('rock_software_messaging_client_outbound_messages')
            ->renameColumn('sender_code', 'account')
            ->update();
        $this->table('rock_software_messaging_client_senders')->drop()->save();
        $this->table('rock_software_messaging_client_channel_settings')->drop()->save();
    }
}
