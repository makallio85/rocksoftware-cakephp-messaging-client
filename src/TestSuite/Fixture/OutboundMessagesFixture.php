<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\TestSuite\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Empty: a fresh installation has no rows here. Tests create what they need.
 */
class OutboundMessagesFixture extends TestFixture
{
    public string $tableAlias = 'RockSoftware/MessagingClient.OutboundMessages';

    public array $records = [];
}
