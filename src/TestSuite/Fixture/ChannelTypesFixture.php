<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\TestSuite\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * The system rows the migration seeds, and nothing else: an application's
 * test bootstrap empties the tables after migrating, so its tests load this
 * to have them. `TypeFixturesTest` keeps it equal to the migration.
 */
class ChannelTypesFixture extends TestFixture
{
    public string $tableAlias = 'RockSoftware/MessagingClient.ChannelTypes';

    public array $records = [
        ['id' => 1, 'value' => 'email', 'label' => 'Email', 'sort_order' => 10],
        ['id' => 2, 'value' => 'sms', 'label' => 'SMS', 'sort_order' => 20],
    ];
}
