<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase\Service;

use Cake\TestSuite\TestCase;
use RockSoftware\MessagingClient\Credentials\StaticCredentialsProvider;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;
use RockSoftware\MessagingClient\Service\ConnectionCheck;
use RockSoftware\MessagingClient\Service\MessagingSettings;
use RockSoftware\MessagingClient\TestSuite\Fixture\MessagingClientFixtures;
use RockSoftware\MessagingClient\TestSuite\MessagingTestTrait;

class MessagingSettingsTest extends TestCase
{
    use MessagingTestTrait;

    protected array $fixtures = MessagingClientFixtures::ALL;

    public function testWithoutCredentialsTheCheckAsksForThem(): void
    {
        $gateway = $this->useFakeGateway();

        $check = (new MessagingSettings(new StaticCredentialsProvider(null)))->checkConnection();

        $this->assertFalse($check->isConfigured);
        $this->assertFalse($check->isConnected());
        $this->assertStringContainsString('API token', (string)$check->error);
        $this->assertSame([], $gateway->requests);
    }

    public function testALiveTokenWithBothSwitchesOnReachesPeople(): void
    {
        $this->useFakeGateway();

        $check = (new MessagingSettings())->checkConnection();

        $this->assertTrue($check->isConnected());
        $this->assertTrue($check->isReachingPeople());
        $this->assertSame([], $check->missingPermissions());
        $this->assertCount(2, $check->connections);
    }

    public function testEachSwitchThatIsOffMeansNobodyIsReached(): void
    {
        $gateway = $this->useFakeGateway(isDeliveryEnabled: false);
        $this->assertFalse((new MessagingSettings())->checkConnection()->isReachingPeople());

        $gateway = $this->useFakeGateway();
        $gateway->status['sending_enabled'] = false;
        $this->assertFalse((new MessagingSettings())->checkConnection()->isReachingPeople());

        $gateway = $this->useFakeGateway();
        $gateway->status['token']['is_test'] = true;
        $this->assertFalse((new MessagingSettings())->checkConnection()->isReachingPeople());
    }

    public function testATokenThatMayNotSendSaysSo(): void
    {
        $gateway = $this->useFakeGateway();
        $gateway->status['permissions'] = ['system.status.read'];

        $check = (new MessagingSettings())->checkConnection();

        $this->assertSame(['message.send'], $check->missingPermissions());
        $this->assertFalse($check->isReachingPeople());
    }

    public function testARefusedTokenIsTheCheckResult(): void
    {
        $this->useFakeGateway()->refuse(401, 'Unknown or revoked token');

        $check = (new MessagingSettings())->checkConnection();

        $this->assertFalse($check->isConnected());
        $this->assertStringContainsString('HTTP 401', (string)$check->error);
    }

    public function testTheCheckSurvivesTheRedirect(): void
    {
        $this->useFakeGateway();
        $check = (new MessagingSettings())->checkConnection();

        $again = ConnectionCheck::fromArray($check->toArray());

        $this->assertEquals($check, $again);
    }

    public function testSendersAreListedPerChannel(): void
    {
        $this->useFakeGateway();
        $settings = new MessagingSettings();
        $settings->refreshSenders();

        $senders = $settings->senders()[ChannelType::SMS];

        $this->assertSame(['test-app-sms'], array_map(fn($sender) => $sender->code, $senders));
    }

    public function testTheLatestMessagesComeNewestFirst(): void
    {
        $this->useFakeGateway();
        $settings = new MessagingSettings();
        $settings->sendTestMessage('a@example.test');
        $settings->sendTestMessage('b@example.test');

        $latest = $settings->latestMessages();

        $this->assertSame(['b@example.test', 'a@example.test'], array_map(fn($m) => $m->recipient, $latest));
        $this->assertNotNull($latest[0]->outbound_message_status_type);
    }
}
