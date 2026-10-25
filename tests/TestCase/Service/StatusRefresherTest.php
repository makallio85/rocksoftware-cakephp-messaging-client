<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase\Service;

use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use RockSoftware\MessagingClient\Messenger;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessage;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessageStatusType;
use RockSoftware\MessagingClient\SendRequest;
use RockSoftware\MessagingClient\Service\StatusRefresher;
use RockSoftware\MessagingClient\TestSuite\FakeGateway;
use RockSoftware\MessagingClient\TestSuite\Fixture\MessagingClientFixtures;
use RockSoftware\MessagingClient\TestSuite\MessagingTestTrait;

class StatusRefresherTest extends TestCase
{
    use MessagingTestTrait;

    protected array $fixtures = MessagingClientFixtures::ALL;

    private FakeGateway $gateway;

    /**
     * @var list<\Cake\Event\EventInterface<mixed>>
     */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        DateTime::setTestNow(new DateTime('2026-10-24 09:00:00'));
        $this->gateway = $this->useFakeGateway();
        EventManager::instance()->on(StatusRefresher::EVENT_STATUS_CHANGED, function (EventInterface $event): void {
            $this->events[] = $event;
        });
    }

    protected function tearDown(): void
    {
        EventManager::instance()->off(StatusRefresher::EVENT_STATUS_CHANGED);
        DateTime::setTestNow(null);
        parent::tearDown();
    }

    public function testADeliveredSmsIsRecordedAndNoLongerAskedAbout(): void
    {
        (new Messenger())->send(SendRequest::sms(to: '0401234567', externalRef: 'sms-1', text: 'Hei'));
        $this->gateway->report('sms-1', 'sent');

        // Not due before the first check.
        $this->assertSame(0, (new StatusRefresher())->refreshDue());

        DateTime::setTestNow(new DateTime('2026-10-24 09:01:00'));
        $this->assertSame(1, (new StatusRefresher())->refreshDue());
        $message = $this->stored('sms-1');
        $this->assertSame(OutboundMessageStatusType::SENT, $message->statusValue());
        $this->assertNotNull($message->next_status_check_at, 'An SMS may still report delivery.');

        $this->gateway->report('sms-1', 'delivered');
        DateTime::setTestNow(new DateTime('2026-10-24 10:00:00'));
        $this->assertSame(1, (new StatusRefresher())->refreshDue());

        $message = $this->stored('sms-1');
        $this->assertSame(OutboundMessageStatusType::DELIVERED, $message->statusValue());
        $this->assertSame('2026-10-24 09:05:00', $message->delivered_at?->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertNotNull($message->final_status_at);
        $this->assertNull($message->next_status_check_at);
        $this->assertSame(
            [[OutboundMessageStatusType::ACCEPTED, OutboundMessageStatusType::SENT], [OutboundMessageStatusType::SENT, OutboundMessageStatusType::DELIVERED]],
            array_map(fn($event) => [$event->getData('previousStatus'), $event->getData('status')], $this->events),
        );
    }

    public function testASentEmailIsFinalSinceEmailNeverReportsDelivery(): void
    {
        (new Messenger())->send(SendRequest::email(to: 'a@example.test', externalRef: 'e-1', subject: 's', text: 'x'));
        $this->gateway->report('e-1', 'sent');

        DateTime::setTestNow(new DateTime('2026-10-24 09:01:00'));
        (new StatusRefresher())->refreshDue();

        $message = $this->stored('e-1');
        $this->assertSame(OutboundMessageStatusType::SENT, $message->statusValue());
        $this->assertNull($message->next_status_check_at);
    }

    public function testABounceIsAFailureWithTheGatewaysReason(): void
    {
        (new Messenger())->send(SendRequest::email(to: 'a@example.test', externalRef: 'e-1', subject: 's', text: 'x'));
        $this->gateway->report('e-1', 'failed', 'bounced', 'Mailbox does not exist');

        DateTime::setTestNow(new DateTime('2026-10-24 09:01:00'));
        (new StatusRefresher())->refreshDue();

        $message = $this->stored('e-1');
        $this->assertSame(OutboundMessageStatusType::FAILED, $message->statusValue());
        $this->assertSame('bounced: Mailbox does not exist', $message->error);
        $this->assertNull($message->next_status_check_at);
    }

    public function testAStillQueuedMessageIsAskedAgainLaterAndNotAfterTheAgeLimit(): void
    {
        (new Messenger())->send(SendRequest::sms(to: '0401234567', externalRef: 'sms-1', text: 'Hei'));

        DateTime::setTestNow(new DateTime('2026-10-24 09:01:00'));
        $this->assertSame(0, (new StatusRefresher())->refreshDue());
        $next = $this->stored('sms-1')->next_status_check_at;
        $this->assertNotNull($next);
        $this->assertTrue($next->greaterThan(DateTime::now()));

        DateTime::setTestNow(new DateTime('2026-10-27 10:00:00'));
        (new StatusRefresher())->refreshDue();
        $this->assertNull($this->stored('sms-1')->next_status_check_at);
        $this->assertSame(OutboundMessageStatusType::ACCEPTED, $this->stored('sms-1')->statusValue());
    }

    public function testATokenThatMayNotReadMessagesStopsTheQuestions(): void
    {
        (new Messenger())->send(SendRequest::sms(to: '0401234567', externalRef: 'sms-1', text: 'Hei'));
        $this->gateway->refuse(403, 'Token lacks permission "message.read"');

        DateTime::setTestNow(new DateTime('2026-10-24 09:01:00'));
        (new StatusRefresher())->refreshDue();

        $this->assertNull($this->stored('sms-1')->next_status_check_at);
    }

    public function testATrappedMessageIsRecordedAsTrapped(): void
    {
        (new Messenger())->send(SendRequest::sms(to: '0401234567', externalRef: 'sms-1', text: 'Hei'));
        $this->gateway->report('sms-1', 'trapped');
        DateTime::setTestNow(new DateTime('2026-10-24 09:01:00'));

        $this->assertSame(1, (new StatusRefresher())->refreshDue());
        $this->assertSame(OutboundMessageStatusType::TRAPPED, $this->stored('sms-1')->statusValue());
    }

    /**
     * @param string $externalRef The ref.
     * @return \RockSoftware\MessagingClient\Model\Entity\OutboundMessage
     */
    private function stored(string $externalRef): OutboundMessage
    {
        /** @var \RockSoftware\MessagingClient\Model\Table\OutboundMessagesTable $table */
        $table = $this->fetchTable('RockSoftware/MessagingClient.OutboundMessages');
        $message = $table->findByExternalRef($externalRef);
        $this->assertNotNull($message);

        return $message;
    }
}
