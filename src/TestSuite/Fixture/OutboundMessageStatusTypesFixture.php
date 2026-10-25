<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\TestSuite\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * The system rows the migration seeds, and nothing else: an application's
 * test bootstrap empties the tables after migrating, so its tests load this
 * to have them. `TypeFixturesTest` keeps it equal to the migration.
 */
class OutboundMessageStatusTypesFixture extends TestFixture
{
    public string $tableAlias = 'RockSoftware/MessagingClient.OutboundMessageStatusTypes';

    public array $records = [
        ['id' => 1, 'value' => 'PENDING', 'label' => 'Waiting to be sent', 'sort_order' => 10],
        ['id' => 2, 'value' => 'ACCEPTED', 'label' => 'Accepted by the gateway', 'sort_order' => 20],
        ['id' => 3, 'value' => 'SUPPRESSED', 'label' => 'Not sent: recipient blocked', 'sort_order' => 30],
        ['id' => 4, 'value' => 'FAILED', 'label' => 'Failed', 'sort_order' => 40],
        ['id' => 5, 'value' => 'NOT_SENT_DELIVERY_OFF', 'label' => 'Not sent: delivery is off', 'sort_order' => 50],
        ['id' => 6, 'value' => 'SENT', 'label' => 'Sent by the provider', 'sort_order' => 60],
        ['id' => 7, 'value' => 'DELIVERED', 'label' => 'Delivered', 'sort_order' => 70],
        ['id' => 8, 'value' => 'TRAPPED', 'label' => 'Trapped: a test, reached no one', 'sort_order' => 80],
        ['id' => 9, 'value' => 'CANCELLED', 'label' => 'Cancelled', 'sort_order' => 90],
    ];
}
