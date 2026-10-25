<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase\Service;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;
use RockSoftware\MessagingClient\Model\Table\ChannelSettingsTable;
use RockSoftware\MessagingClient\Service\AppBaseUrl;
use RockSoftware\MessagingClient\Service\DeliveryGate;
use RockSoftware\MessagingClient\TestSuite\Fixture\MessagingClientFixtures;

class DeliveryGateTest extends TestCase
{
    protected array $fixtures = MessagingClientFixtures::ALL;

    private ChannelSettingsTable $switches;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://app.example.test');
        /** @var \RockSoftware\MessagingClient\Model\Table\ChannelSettingsTable $switches */
        $switches = $this->fetchTable('RockSoftware/MessagingClient.ChannelSettings');
        $this->switches = $switches;
    }

    public function testAChannelNeverSwitchedIsOff(): void
    {
        $this->assertFalse(DeliveryGate::isEnabledFor(ChannelType::EMAIL));
        $this->assertFalse(DeliveryGate::isAnyEnabled());
        $this->assertFalse(DeliveryGate::switchOf(ChannelType::EMAIL)->isSwitchedOn);
    }

    public function testAChannelSwitchedOnHereIsOnAndOnlyThatChannel(): void
    {
        $this->switches->switchSending(ChannelType::EMAIL, true, 'https://app.example.test', 'Test User');

        $this->assertTrue(DeliveryGate::isEnabledFor(ChannelType::EMAIL));
        $this->assertFalse(DeliveryGate::isEnabledFor(ChannelType::SMS));
        $this->assertTrue(DeliveryGate::isAnyEnabled());
    }

    public function testADatabaseRestoredOnAnotherHostReadsAsOff(): void
    {
        // Switched on in production…
        $this->switches->switchSending(ChannelType::EMAIL, true, 'https://app.example.test', 'Test User');
        $this->switches->switchSending(ChannelType::SMS, true, 'https://app.example.test', 'Test User');

        // …and the same database read by a development copy.
        Configure::write('App.fullBaseUrl', 'https://app-staging.example.test');

        $this->assertFalse(DeliveryGate::isEnabledFor(ChannelType::EMAIL));
        $this->assertFalse(DeliveryGate::isEnabledFor(ChannelType::SMS));
        $switch = DeliveryGate::switchOf(ChannelType::EMAIL);
        $this->assertTrue($switch->isSwitchedOn);
        $this->assertTrue($switch->isSwitchedOnElsewhere());
        $this->assertSame('https://app.example.test', $switch->enabledForUrl);
    }

    public function testWithoutAConfiguredAddressNothingIsOn(): void
    {
        $this->switches->switchSending(ChannelType::EMAIL, true, 'https://app.example.test', 'Test User');
        Configure::write('App.fullBaseUrl', null);

        $this->assertFalse(DeliveryGate::isEnabledFor(ChannelType::EMAIL));
    }

    public function testTheAddressIsComparedNormalised(): void
    {
        $this->switches->switchSending(ChannelType::EMAIL, true, 'https://app.example.test', 'Test User');
        Configure::write('App.fullBaseUrl', 'HTTPS://APP.Example.test:443/');

        $this->assertTrue(DeliveryGate::isEnabledFor(ChannelType::EMAIL));
        $this->assertSame('https://app.example.test:8443/app', AppBaseUrl::normalise('https://APP.example.test:8443/app/'));
        $this->assertNull(AppBaseUrl::normalise('ftp://app.example.test'));
    }

    public function testTheApplicationCanForceOffButNeverOn(): void
    {
        $this->switches->switchSending(ChannelType::EMAIL, true, 'https://app.example.test', 'Test User');

        Configure::write('RockSoftwareMessagingClient.isDeliveryEnabled', false);
        $this->assertFalse(DeliveryGate::isEnabledFor(ChannelType::EMAIL));

        Configure::write('RockSoftwareMessagingClient.isDeliveryEnabled', fn(string $channel): bool => $channel !== 'email');
        $this->assertFalse(DeliveryGate::isEnabledFor(ChannelType::EMAIL));
        $this->assertTrue(DeliveryGate::switchOf(ChannelType::EMAIL)->isForcedOff);

        Configure::write('RockSoftwareMessagingClient.isDeliveryEnabled', true);
        $this->assertTrue(DeliveryGate::isEnabledFor(ChannelType::EMAIL));
        $this->assertFalse(DeliveryGate::isEnabledFor(ChannelType::SMS));
    }
}
