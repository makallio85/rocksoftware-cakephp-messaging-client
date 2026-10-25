<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\TestSuite\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Empty: a fresh installation has every channel switched off.
 */
class ChannelSettingsFixture extends TestFixture
{
    public string $tableAlias = 'RockSoftware/MessagingClient.ChannelSettings';

    public array $records = [];
}
