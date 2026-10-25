<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use RockSoftware\MessagingClient\Messenger;
use RockSoftware\MessagingClient\SendRequest;
use RockSoftware\MessagingClient\TestSuite\Fixture\MessagingClientFixtures;
use RockSoftware\MessagingClient\TestSuite\MessagingTestTrait;

class DeliverCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;
    use MessagingTestTrait;

    protected array $fixtures = MessagingClientFixtures::ALL;

    protected function tearDown(): void
    {
        DateTime::setTestNow(null);
        parent::tearDown();
    }

    public function testItSendsWhatIsDue(): void
    {
        DateTime::setTestNow(new DateTime('2026-10-24 09:00:00'));
        $gateway = $this->useFakeGateway();
        $gateway->refuse(429, 'Quota');
        (new Messenger())->send(SendRequest::sms(to: '0401234567', externalRef: 'r-1', text: 'Hei'));

        DateTime::setTestNow(new DateTime('2026-10-24 09:10:00'));
        $this->exec('messaging_client deliver');

        $this->assertExitSuccess();
        $this->assertOutputContains('Tried 1 outbound message(s): ACCEPTED 1.');
        $this->assertMessageCount(1);
    }

    public function testAChannelSwitchedOnForAnotherAddressIsWarnedAbout(): void
    {
        $this->useFakeGateway();
        Configure::write('App.fullBaseUrl', 'https://worker.internal');

        $this->exec('messaging_client deliver');

        $this->assertExitSuccess();
        $this->assertErrorContains(
            'email sending is switched on for https://app.example.test, but App.fullBaseUrl here is '
            . 'https://worker.internal',
        );
    }

    public function testNothingDueIsQuiet(): void
    {
        $this->useFakeGateway();

        $this->exec('messaging_client deliver --limit 5');

        $this->assertExitSuccess();
        $this->assertOutputEmpty();
        $this->assertErrorEmpty();
    }
}
