<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\TestSuite\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Empty: a fresh installation has no senders until the gateway is asked.
 */
class SendersFixture extends TestFixture
{
    public string $tableAlias = 'RockSoftware/MessagingClient.Senders';

    public array $records = [];
}
