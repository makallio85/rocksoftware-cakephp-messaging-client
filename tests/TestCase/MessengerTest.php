<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase;

use Cake\Core\Configure;
use Cake\Http\Client\Exception\NetworkException;
use Cake\Http\Client\Request;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RockSoftware\MessagingClient\Credentials\StaticCredentialsProvider;
use RockSoftware\MessagingClient\Gateway\GatewayClient;
use RockSoftware\MessagingClient\Messenger;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessage;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessageStatusType;
use RockSoftware\MessagingClient\Model\Table\SendersTable;
use RockSoftware\MessagingClient\SendRequest;
use RockSoftware\MessagingClient\TestSuite\FakeGateway;
use RockSoftware\MessagingClient\TestSuite\Fixture\MessagingClientFixtures;
use RockSoftware\MessagingClient\TestSuite\MessagingTestTrait;

class MessengerTest extends TestCase
{
    use MessagingTestTrait;

    protected array $fixtures = MessagingClientFixtures::ALL;

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        DateTime::setTestNow(new DateTime('2026-10-24 09:00:00'));
        $this->gateway = $this->useFakeGateway();
    }

    protected function tearDown(): void
    {
        DateTime::setTestNow(null);
        parent::tearDown();
    }

    public function testAMessageIsRecordedThenAccepted(): void
    {
        $message = (new Messenger())->send($this->email('approval-17'));

        $this->assertSame(OutboundMessageStatusType::ACCEPTED, $message->statusValue());
        $this->assertSame(ChannelType::EMAIL, $message->channelValue());
        $this->assertNotNull($message->gateway_message_uuid);
        $this->assertSame(1, $message->attempts);
        $this->assertSame('liisa@example.test', $message->payload['to'] ?? null, 'Kept for resending.');
        $this->assertNull($message->error);
        $this->assertMessageSent(['to' => 'liisa@example.test', 'subject' => 'A change request waits']);

        $stored = $this->stored('approval-17');
        $this->assertSame(OutboundMessageStatusType::ACCEPTED, $stored->statusValue());
        $this->assertSame('liisa@example.test', $stored->recipient);
        $this->assertSame(['change_request_id' => 17], $stored->context);
        $this->assertNull($stored->next_attempt_at);
    }

    public function testTheSameExternalRefIsSentOnce(): void
    {
        $messenger = new Messenger();
        $first = $messenger->send($this->email('approval-17'));
        $second = $messenger->send($this->email('approval-17'));

        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $this->gateway->sendRequests());
        $this->assertMessageCount(1);
    }

    public function testWithDeliveryOffNothingReachesTheGateway(): void
    {
        Configure::write('RockSoftwareMessagingClient.isDeliveryEnabled', false);

        $message = (new Messenger())->send($this->email('approval-17'));

        $this->assertSame(OutboundMessageStatusType::NOT_SENT_DELIVERY_OFF, $message->statusValue());
        $this->assertStringContainsString('switched off', (string)$message->error);
        $this->assertSame([], $this->gateway->requests);
    }

    public function testEachChannelHasItsOwnSwitch(): void
    {
        /** @var \RockSoftware\MessagingClient\Model\Table\ChannelSettingsTable $switches */
        $switches = $this->fetchTable('RockSoftware/MessagingClient.ChannelSettings');
        $switches->switchSending(ChannelType::SMS, false, null, 'someone');

        $email = (new Messenger())->send($this->email('approval-17'));
        $sms = (new Messenger())->send(SendRequest::sms(to: '0401234567', externalRef: 'sms-1', text: 'x'));

        $this->assertSame(OutboundMessageStatusType::ACCEPTED, $email->statusValue());
        $this->assertSame(OutboundMessageStatusType::NOT_SENT_DELIVERY_OFF, $sms->statusValue());
        $this->assertCount(1, $this->gateway->sendRequests());
    }

    public function testTheApplicationCannotForceSendingOn(): void
    {
        $this->useFakeGateway(isDeliveryEnabled: false);
        Configure::write('RockSoftwareMessagingClient.isDeliveryEnabled', true);

        $message = (new Messenger())->send($this->email('approval-17'));

        $this->assertSame(OutboundMessageStatusType::NOT_SENT_DELIVERY_OFF, $message->statusValue());
    }

    public function testATemporaryErrorLeavesItPendingAndTheWorkerSendsItUnderTheSameRef(): void
    {
        $this->gateway->failWith(new NetworkException('timed out', new Request()));

        $message = (new Messenger())->send($this->email('approval-17'));

        $this->assertSame(OutboundMessageStatusType::PENDING, $message->statusValue());
        $this->assertStringContainsString('did not answer', (string)$message->error);
        $this->assertSame('2026-10-24 09:01:00', $message->next_attempt_at?->format('Y-m-d H:i:s'));
        $this->assertNotNull($this->stored('approval-17')->payload);

        // Not due yet.
        $this->assertSame(0, array_sum((new Messenger())->deliverDue()));

        DateTime::setTestNow(new DateTime('2026-10-24 09:01:00'));
        $counts = (new Messenger())->deliverDue();

        $this->assertSame(1, $counts[OutboundMessageStatusType::ACCEPTED]);
        $stored = $this->stored('approval-17');
        $this->assertSame(OutboundMessageStatusType::ACCEPTED, $stored->statusValue());
        $this->assertSame(2, $stored->attempts);
        $this->assertNull($stored->error);
        $refs = array_column($this->gateway->sendRequests(), 'external_ref');
        $this->assertSame(['approval-17', 'approval-17'], $refs);
    }

    public function testAnUnknownOutcomeThatWasInFactAcceptedIsNotSentTwice(): void
    {
        // The gateway took the first request but its answer was lost.
        $this->gateway->answer(502, '');

        (new Messenger())->send($this->email('approval-17'));
        DateTime::setTestNow(new DateTime('2026-10-24 09:05:00'));
        (new Messenger())->deliverDue();

        $this->assertCount(2, $this->gateway->sendRequests());
        $this->assertSame(
            OutboundMessageStatusType::ACCEPTED,
            $this->stored('approval-17')->statusValue(),
        );
    }

    public function testARefusalFailsItAtOnceWithTheGatewaysReason(): void
    {
        $this->gateway->refuse(422, 'The recipient is not an email address');

        $message = (new Messenger())->send($this->email('approval-17'));

        $this->assertSame(OutboundMessageStatusType::FAILED, $message->statusValue());
        $this->assertStringContainsString('The recipient is not an email address', (string)$message->error);
        $this->assertNull($message->next_attempt_at);
    }

    public function testASuppressedRecipientIsRecordedAsSuch(): void
    {
        $this->gateway->suppressedIdentities = ['liisa@example.test'];

        $message = (new Messenger())->send($this->email('approval-17'));

        $this->assertSame(OutboundMessageStatusType::SUPPRESSED, $message->statusValue());
        $this->assertMessageCount(0);
    }

    public function testATrappedMessageIsMarkedTest(): void
    {
        $this->gateway->isTrapping = true;

        $message = (new Messenger())->send($this->email('approval-17'));

        $this->assertTrue($message->is_test);
    }

    public function testSwitchingAChannelOnNeverSendsWhatWasRecordedWhileItWasOff(): void
    {
        $this->useFakeGateway(isDeliveryEnabled: false);
        (new Messenger())->send($this->email('approval-17'));

        /** @var \RockSoftware\MessagingClient\Model\Table\ChannelSettingsTable $switches */
        $switches = $this->fetchTable('RockSoftware/MessagingClient.ChannelSettings');
        $switches->switchSending(ChannelType::EMAIL, true, 'https://app.example.test', 'Test User');
        DateTime::setTestNow(new DateTime('2026-10-24 12:00:00'));
        (new Messenger())->deliverDue();

        $this->assertSame(
            OutboundMessageStatusType::NOT_SENT_DELIVERY_OFF,
            $this->stored('approval-17')->statusValue(),
        );
        $this->assertSame([], $this->gateway->sendRequests());
    }

    public function testAResendIsANewMessageAndTheOldOneIsLeftAsItWas(): void
    {
        $this->gateway->refuse(422, 'Mailbox full');
        $old = (new Messenger())->send($this->email('approval-17'));
        $before = $this->stored('approval-17')->toArray();

        $new = (new Messenger())->resend($old, 'approval-17-again');

        $this->assertSame(OutboundMessageStatusType::ACCEPTED, $new->statusValue());
        $this->assertSame($old->id, $new->resent_from_id);
        $this->assertSame(['change_request_id' => 17], $new->context);
        $this->assertMessageSent(['external_ref' => 'approval-17-again', 'subject' => 'A change request waits']);
        $this->assertEquals($before, $this->stored('approval-17')->toArray());
    }

    public function testAPendingMessageOrATakenRefIsNotResent(): void
    {
        $this->gateway->refuse(500, 'Down');
        $pending = (new Messenger())->send($this->email('approval-17'));
        $done = (new Messenger())->send($this->email('approval-18'));

        foreach ([[$pending, 'x-1'], [$done, 'approval-17']] as [$message, $ref]) {
            try {
                (new Messenger())->resend($message, $ref);
                $this->fail('It was resent.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testItGivesUpWhenTheRetriesRunOut(): void
    {
        Configure::write('RockSoftwareMessagingClient.retryDelays', [60, 120]);
        foreach (range(1, 3) as $attempt) {
            $this->gateway->refuse(503, 'Off');
        }

        $message = (new Messenger())->send($this->email('approval-17'));
        $this->assertTrue($message->isPending());
        DateTime::setTestNow(new DateTime('2026-10-24 09:01:00'));
        (new Messenger())->deliverDue();
        $this->assertTrue($this->stored('approval-17')->isPending());
        $this->assertSame('2026-10-24 09:03:00', $this->stored('approval-17')->next_attempt_at?->format('Y-m-d H:i:s'));
        DateTime::setTestNow(new DateTime('2026-10-24 09:03:00'));
        (new Messenger())->deliverDue();

        $stored = $this->stored('approval-17');
        $this->assertSame(OutboundMessageStatusType::FAILED, $stored->statusValue());
        $this->assertStringContainsString('Gave up after 3 attempts', (string)$stored->error);
        $this->assertSame(3, $stored->attempts);
    }

    public function testAMessagePastItsDeadlineIsNeverSent(): void
    {
        $this->gateway->refuse(500, 'Down');
        (new Messenger())->send(SendRequest::sms(to: '0401234567', externalRef: 'slot-4', text: 'x', validFor: 30));

        DateTime::setTestNow(new DateTime('2026-10-24 09:01:00'));
        (new Messenger())->deliverDue();

        $stored = $this->stored('slot-4');
        $this->assertSame(OutboundMessageStatusType::FAILED, $stored->statusValue());
        $this->assertStringContainsString('expired', (string)$stored->error);
        $this->assertCount(1, $this->gateway->sendRequests());
        $this->assertSame('2026-10-24T09:00:30+00:00', $this->gateway->sendRequests()[0]['expires_at']);
    }

    public function testAPendingBacklogIsNotSentOnceDeliveryIsOff(): void
    {
        $this->gateway->refuse(500, 'Down');
        (new Messenger())->send($this->email('approval-17'));

        // The database is restored onto a development host.
        Configure::write('App.fullBaseUrl', 'https://app-dev.example.test');
        DateTime::setTestNow(new DateTime('2026-10-24 10:00:00'));
        $counts = (new Messenger())->deliverDue();

        $this->assertSame(1, $counts[OutboundMessageStatusType::NOT_SENT_DELIVERY_OFF]);
        $this->assertCount(1, $this->gateway->sendRequests());
    }

    public function testWithoutCredentialsItWaitsForTheSettings(): void
    {
        $message = (new Messenger(new StaticCredentialsProvider(null)))->send($this->email('approval-17'));

        $this->assertTrue($message->isPending());
        $this->assertStringContainsString('not set up', (string)$message->error);
        $this->assertSame([], $this->gateway->requests);
    }

    public function testTheChannelsDefaultSenderIsUsedWhenTheRequestNamesNone(): void
    {
        $senders = $this->senders();
        $senders->setDefault('test-app-email');

        $email = (new Messenger())->send($this->email('approval-17'));
        (new Messenger())->send(SendRequest::sms(to: '0401234567', externalRef: 'sms-1', text: 'x'));

        $this->assertSame('test-app-email', $email->sender_code);
        $this->assertSame('test-app-email', $this->stored('approval-17')->sender_code);
        $this->assertMessageSent(['external_ref' => 'approval-17', 'account' => 'test-app-email']);
        // SMS has no default: the gateway's own default for the application.
        $this->assertArrayNotHasKey('account', $this->gateway->sendRequests()[1]);
        $this->assertNull($this->stored('sms-1')->sender_code);
    }

    public function testANamedSenderIsUsed(): void
    {
        $this->senders();

        $message = (new Messenger())->send(SendRequest::sms(
            to: '0401234567',
            externalRef: 'sms-1',
            text: 'x',
            sender: 'test-app-sms',
        ));

        $this->assertSame('test-app-sms', $message->sender_code);
        $this->assertMessageSent(['external_ref' => 'sms-1', 'account' => 'test-app-sms']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedSenders(): array
    {
        return [
            'unknown' => ['sms', 'nobody'],
            'another channel' => ['sms', 'test-app-email'],
            'disabled' => ['email', 'disabled-email'],
            'gone from the gateway' => ['email', 'gone-email'],
        ];
    }

    #[DataProvider('refusedSenders')]
    public function testASenderItMayNotUseIsRefusedBeforeAnythingIsStored(string $channel, string $code): void
    {
        $senders = $this->senders();
        $this->gateway->connections[] = ['code' => 'disabled-email', 'channel' => 'email', 'outbound_enabled' => true];
        $this->gateway->connections[] = ['code' => 'gone-email', 'channel' => 'email', 'outbound_enabled' => true];
        $senders->refresh((new GatewayClient(FakeGateway::BASE_URL, FakeGateway::TOKEN, $this->gateway))->connections());
        $senders->setEnabled('disabled-email', false);
        array_pop($this->gateway->connections);
        $senders->refresh((new GatewayClient(FakeGateway::BASE_URL, FakeGateway::TOKEN, $this->gateway))->connections());
        $request = $channel === 'sms'
            ? SendRequest::sms(to: '0401234567', externalRef: 'r-1', text: 'x', sender: $code)
            : SendRequest::email(to: 'a@example.test', externalRef: 'r-1', subject: 's', text: 'x', sender: $code);

        try {
            (new Messenger())->send($request);
            $this->fail('The sender was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($code, $exception->getMessage());
        }
        $this->assertSame(0, $this->fetchTable('RockSoftware/MessagingClient.OutboundMessages')->find()->count());
    }

    public function testASenderHandedOverInTheOldSettingsColumnsBecomesTheDefault(): void
    {
        $settings = $this->fetchTable('RockSoftware/MessagingClient.Settings');
        $settings->updateAll(['email_account' => 'app-email'], ['1 = 1']);

        $message = (new Messenger())->send($this->email('approval-17'));

        $this->assertSame('app-email', $message->sender_code);
        $this->assertNull($settings->find()->firstOrFail()->get('email_account'));
    }

    /**
     * The fake gateway's senders, refreshed as the settings page does.
     *
     * @return \RockSoftware\MessagingClient\Model\Table\SendersTable
     */
    private function senders(): SendersTable
    {
        /** @var \RockSoftware\MessagingClient\Model\Table\SendersTable $senders */
        $senders = $this->fetchTable('RockSoftware/MessagingClient.Senders');
        $senders->refresh((new GatewayClient(FakeGateway::BASE_URL, FakeGateway::TOKEN, $this->gateway))->connections());
        $this->gateway->requests = [];

        return $senders;
    }

    public function testAMessageAnotherWorkerClaimedIsLeftToIt(): void
    {
        $this->gateway->refuse(500, 'Down');
        (new Messenger())->send($this->email('approval-17'));
        DateTime::setTestNow(new DateTime('2026-10-24 09:02:00'));

        /** @var \RockSoftware\MessagingClient\Model\Table\OutboundMessagesTable $table */
        $table = $this->fetchTable('RockSoftware/MessagingClient.OutboundMessages');
        $mine = $table->find('due', now: DateTime::now())->firstOrFail();
        $theirs = $table->find('due', now: DateTime::now())->firstOrFail();
        $this->assertInstanceOf(OutboundMessage::class, $mine);
        $this->assertInstanceOf(OutboundMessage::class, $theirs);
        $this->assertTrue($table->claim($theirs, DateTime::now()->addMinutes(5)));
        $this->assertFalse($table->claim($mine, DateTime::now()->addMinutes(5)));

        $this->assertSame(0, array_sum((new Messenger())->deliverDue()));
        $this->assertCount(1, $this->gateway->sendRequests());
    }

    /**
     * @param string $externalRef The ref.
     * @return \RockSoftware\MessagingClient\SendRequest
     */
    private function email(string $externalRef): SendRequest
    {
        return SendRequest::email(
            to: 'liisa@example.test',
            externalRef: $externalRef,
            subject: 'A change request waits',
            text: 'Open it.',
            context: ['change_request_id' => 17],
        );
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
