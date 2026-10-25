<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase\TestSuite;

use Cake\TestSuite\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use RockSoftware\MessagingClient\Messenger;
use RockSoftware\MessagingClient\SendRequest;
use RockSoftware\MessagingClient\TestSuite\Fixture\MessagingClientFixtures;
use RockSoftware\MessagingClient\TestSuite\MessagingTestTrait;

/**
 * What an application's own tests get.
 */
class MessagingTestTraitTest extends TestCase
{
    use MessagingTestTrait;

    protected array $fixtures = MessagingClientFixtures::ALL;

    public function testAssertionsSeeWhatWouldHaveBeenSent(): void
    {
        $this->useFakeGateway();
        $this->assertNoMessageSent();

        (new Messenger())->send(SendRequest::sms(to: '0401234567', externalRef: 'r-1', text: 'Hei'));

        $this->assertMessageSent(['channel' => 'sms', 'to' => '+358401234567', 'text' => 'Hei']);
        $this->assertMessageCount(1);
    }

    public function testAMessageThatWasNotSentFailsTheAssertion(): void
    {
        $this->useFakeGateway();
        (new Messenger())->send(SendRequest::sms(to: '0401234567', externalRef: 'r-1', text: 'Hei'));

        $this->expectException(AssertionFailedError::class);
        $this->assertMessageSent(['to' => '+358401234568']);
    }
}
