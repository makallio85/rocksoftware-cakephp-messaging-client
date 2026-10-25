<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase;

use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;
use RockSoftware\MessagingClient\TestSuite\Fixture\ChannelTypesFixture;
use RockSoftware\MessagingClient\TestSuite\Fixture\MessagingClientFixtures;
use RockSoftware\MessagingClient\TestSuite\Fixture\OutboundMessageStatusTypesFixture;

/**
 * The type-table fixtures applications load hold exactly the rows the
 * migration seeds: a fresh installation's, nothing more and nothing less.
 */
class TypeFixturesTest extends TestCase
{
    private const CONNECTION = 'rsmc_migration_check';

    public function testTheFixturesHoldTheMigrationsSystemRows(): void
    {
        $file = TMP . 'migration_check.sqlite';
        if (is_file($file)) {
            unlink($file);
        }
        ConnectionManager::setConfig(self::CONNECTION, ['url' => 'sqlite:///' . $file]);
        try {
            (new Migrations(['connection' => self::CONNECTION, 'plugin' => 'RockSoftware/MessagingClient']))->migrate();
            $connection = ConnectionManager::get(self::CONNECTION);
            foreach ([new ChannelTypesFixture(), new OutboundMessageStatusTypesFixture()] as $fixture) {
                $rows = $connection->selectQuery(['id', 'value', 'label', 'sort_order'], $fixture->table)
                    ->orderBy(['id' => 'ASC'])
                    ->execute()
                    ->fetchAll('assoc');
                $rows = array_map(fn(array $row): array => [
                    'id' => (int)$row['id'],
                    'value' => $row['value'],
                    'label' => $row['label'],
                    'sort_order' => (int)$row['sort_order'],
                ], $rows);

                $this->assertSame($rows, $fixture->records, $fixture->table);
            }
        } finally {
            ConnectionManager::drop(self::CONNECTION);
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testTheFixturesLoadWhenTheApplicationAllowsNoFallbackTables(): void
    {
        $locator = TableRegistry::getTableLocator();
        $locator->clear();
        $locator->allowFallbackClass(false);
        try {
            foreach (MessagingClientFixtures::ALL as $class) {
                // The constructor resolves the Table class, as loading the fixture in a test does.
                $fixture = new $class();

                $this->assertStringStartsWith('rock_software_messaging_client_', $fixture->table, $class);
                $this->assertStringStartsWith('RockSoftware/MessagingClient.', $fixture->tableAlias, $class);
            }
        } finally {
            $locator->allowFallbackClass(true);
            $locator->clear();
        }
    }
}
