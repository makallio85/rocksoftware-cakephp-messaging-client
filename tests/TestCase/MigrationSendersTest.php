<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

/**
 * The sender a first installation stored per channel (an application's own data
 * migration writes it there) becomes that channel's default sender.
 */
class MigrationSendersTest extends TestCase
{
    private const CONNECTION = 'rsmc_migration_senders';

    public function testTheStoredSenderPerChannelBecomesItsDefaultSender(): void
    {
        $file = TMP . 'migration_senders.sqlite';
        if (is_file($file)) {
            unlink($file);
        }
        ConnectionManager::setConfig(self::CONNECTION, ['url' => 'sqlite:///' . $file]);
        try {
            $migrations = new Migrations(['connection' => self::CONNECTION, 'plugin' => 'RockSoftware/MessagingClient']);
            $migrations->migrate(['target' => 20261024090000]);
            $connection = ConnectionManager::get(self::CONNECTION);
            $connection->insertQuery('rock_software_messaging_client_settings', [
                'gateway_url' => 'https://messaging.example.test',
                'api_token' => 'encrypted',
                'email_account' => 'app-email',
                'sms_account' => null,
                'created' => '2026-10-24 09:00:00',
                'modified' => '2026-10-24 09:00:00',
            ])->execute();

            $migrations->migrate();

            $senders = $connection->selectQuery(['code', 'is_enabled', 'is_default'], 'rock_software_messaging_client_senders')
                ->execute()
                ->fetchAll('assoc');
            $this->assertCount(1, $senders);
            $this->assertSame('app-email', $senders[0]['code']);
            $this->assertEquals(1, $senders[0]['is_default']);
            $this->assertEquals(1, $senders[0]['is_enabled']);
            $setting = $connection->selectQuery(['email_account'], 'rock_software_messaging_client_settings')
                ->execute()
                ->fetch('assoc');
            $this->assertNull($setting['email_account']);
            $columns = $connection->getSchemaCollection()
                ->describe('rock_software_messaging_client_outbound_messages')
                ->columns();
            $this->assertContains('sender_code', $columns);
            $this->assertNotContains('account', $columns);
        } finally {
            ConnectionManager::drop(self::CONNECTION);
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
