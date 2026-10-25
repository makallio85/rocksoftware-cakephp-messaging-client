<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use RockSoftware\MessagingClient\Messenger;
use RockSoftware\MessagingClient\SendRequest;
use RockSoftware\MessagingClient\TestSuite\Fixture\MessagingClientFixtures;
use RockSoftware\MessagingClient\TestSuite\MessagingTestTrait;

class HealthControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use MessagingTestTrait;

    protected array $fixtures = MessagingClientFixtures::ALL;

    protected function tearDown(): void
    {
        DateTime::setTestNow(null);
        parent::tearDown();
    }

    public function testAWorkingSetupIsOkWithoutSigningIn(): void
    {
        Configure::write('RockSoftwareMessagingClient.settingsAccess', fn(): bool => false);
        $this->useFakeGateway();

        $this->get('/messaging-client/health?strict=1');

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('ok', $body['status']);
        $this->assertSame([], $body['problems']);
        $this->assertArrayHasKey('time', $body);
    }

    public function testANewInstallationIsDegradedAndStrictAnswers503(): void
    {
        $this->get('/messaging-client/health');
        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('degraded', $body['status']);
        $this->assertSame(['gateway_not_configured', 'no_channel_enabled'], $body['problems']);

        $this->get('/messaging-client/health?strict=1');
        $this->assertResponseCode(503);
    }

    public function testStuckAndFailingMessagesAreReportedWithoutSayingWhoTheyWereFor(): void
    {
        DateTime::setTestNow(new DateTime('2026-10-24 08:00:00'));
        $gateway = $this->useFakeGateway();
        $gateway->refuse(500, 'Down');
        (new Messenger())->send(SendRequest::email(to: 'liisa@example.test', externalRef: 'p-1', subject: 'Secret', text: 'x'));
        Configure::write('RockSoftwareMessagingClient.healthFailureThreshold', 2);
        foreach ([1, 2] as $i) {
            $gateway->refuse(422, 'Bad');
            (new Messenger())->send(SendRequest::email(to: 'matti@example.test', externalRef: "f-{$i}", subject: 'Secret', text: 'x'));
        }
        DateTime::setTestNow(new DateTime('2026-10-24 09:00:00'));

        $this->get('/messaging-client/health');

        $body = (string)$this->_response->getBody();
        $this->assertSame(
            ['messages_stuck_pending', 'recent_failures'],
            json_decode($body, true)['problems'],
        );
        $this->assertStringNotContainsString('example.test', $body);
        $this->assertStringNotContainsString('Secret', $body);
    }
}
