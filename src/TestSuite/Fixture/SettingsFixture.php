<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\TestSuite\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Empty: a fresh installation has no rows here. Tests create what they need.
 */
class SettingsFixture extends TestFixture
{
    public string $tableAlias = 'RockSoftware/MessagingClient.Settings';

    public array $records = [];
}
