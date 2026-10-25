<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase\Gateway;

use Cake\Http\Client\Exception\NetworkException;
use Cake\Http\Client\Request;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RockSoftware\MessagingClient\Gateway\GatewayClient;
use RockSoftware\MessagingClient\Gateway\GatewayException;
use RockSoftware\MessagingClient\Gateway\SendResult;
use RockSoftware\MessagingClient\SendRequest;
use RockSoftware\MessagingClient\TestSuite\FakeGateway;

class GatewayClientTest extends TestCase
{
    private FakeGateway $gateway;

    private GatewayClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeGateway();
        $this->client = new GatewayClient(FakeGateway::BASE_URL, 'secret-token', $this->gateway);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedAddresses(): array
    {
        return [
            'http' => ['http://messaging.example.test'],
            'a path' => ['https://messaging.example.test/public-api/v1'],
            'credentials in the address' => ['https://user@messaging.example.test'],
            'a backslash' => ['https://messaging.example.test\\@evil.test'],
            'a query' => ['https://messaging.example.test?x=1'],
            'a bad label' => ['https://-bad.example.test'],
            'empty' => [''],
        ];
    }

    #[DataProvider('refusedAddresses')]
    public function testOnlyAPlainHttpsHostIsAnAddress(string $address): void
    {
        $this->expectException(InvalidArgumentException::class);
        GatewayClient::apiRoot($address);
    }

    public function testTheApiRootIsBuiltFromTheHost(): void
    {
        $this->assertSame(
            'https://messaging.example.test:8443/public-api/v1/',
            GatewayClient::apiRoot(' https://Messaging.Example.test:8443/ '),
        );
    }

    public function testTheTokenTravelsOnlyInTheAuthorizationHeader(): void
    {
        $this->client->send(SendRequest::sms(to: '0401234567', externalRef: 'r-1', text: 'Hei'));

        $request = $this->gateway->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://messaging.example.test/public-api/v1/messages', $request['url']);
        $this->assertSame('Bearer secret-token', $request['headers']['Authorization']);
        $this->assertStringNotContainsString('secret-token', (string)json_encode($request['body']));
        $this->assertStringNotContainsString('secret-token', print_r($this->client, true));
    }

    public function testARedirectIsNeverFollowed(): void
    {
        $this->gateway->answer(302, '', ['Location: https://evil.example.test/steal']);

        try {
            $this->client->status();
            $this->fail('A redirect was followed.');
        } catch (GatewayException $exception) {
            $this->assertSame(302, $exception->httpStatus);
            $this->assertStringContainsString('redirect', $exception->getMessage());
        }
        $this->assertCount(1, $this->gateway->requests);
    }

    public function testStatusSaysWhetherAnythingReachesAPerson(): void
    {
        $this->gateway->status['token'] = ['name' => 'app-production', 'is_test' => true];
        $this->gateway->status['sending_enabled'] = false;

        $status = $this->client->status();

        $this->assertSame('app-production', $status->tokenName);
        $this->assertTrue($status->isTestToken);
        $this->assertFalse($status->isSendingEnabled);
        $this->assertTrue($status->hasPermission('message.send'));
    }

    public function testATokenTheGatewayDoesNotCallLiveCountsAsATestToken(): void
    {
        unset($this->gateway->status['token']);

        $this->assertTrue($this->client->status()->isTestToken);
    }

    public function testConnectionsAreWhatTheTokenMaySendAs(): void
    {
        $this->gateway->connections[] = ['code' => 'broken'];

        $connections = $this->client->connections();

        $this->assertCount(2, $connections);
        $this->assertSame('test-app-email', $connections[0]->code);
        $this->assertSame('email', $connections[0]->channel);
        $this->assertSame('app@example.test', $connections[0]->identity);
        $this->assertTrue($connections[0]->isOutboundEnabled);
    }

    public function testASendIsQueuedOnceAndAnsweredAlreadyAcceptedAfter(): void
    {
        $request = SendRequest::sms(to: '0401234567', externalRef: 'r-1', text: 'Hei');

        $first = $this->client->send($request);
        $second = $this->client->send($request);

        $this->assertSame(SendResult::RESULT_QUEUED, $first->result);
        $this->assertSame(SendResult::RESULT_ALREADY_ACCEPTED, $second->result);
        $this->assertSame($first->message->uuid, $second->message->uuid);
        $this->assertCount(1, $this->gateway->acceptedMessages());
    }

    public function testASuppressedRecipientIsAResultNotAnError(): void
    {
        $this->gateway->suppressedIdentities = ['+358401234567'];

        $result = $this->client->send(SendRequest::sms(to: '0401234567', externalRef: 'r-1', text: 'Hei'));

        $this->assertTrue($result->isSuppressed());
    }

    /**
     * @return array<string, array{0: int, 1: bool, 2: bool}>
     */
    public static function refusals(): array
    {
        // status, isTemporary, isOutcomeUnknown
        return [
            'revoked token' => [401, true, false],
            'not permitted' => [403, false, false],
            'no such connection' => [404, false, false],
            'invalid message' => [422, false, false],
            'quota' => [429, true, false],
            'server error' => [500, true, true],
            'bad gateway' => [502, true, true],
            'switched off' => [503, true, false],
        ];
    }

    #[DataProvider('refusals')]
    public function testARefusalSaysWhetherTryingAgainCanHelp(int $status, bool $isTemporary, bool $isUnknown): void
    {
        $this->gateway->refuse($status, 'The gateway says why');

        try {
            $this->client->send(SendRequest::sms(to: '0401234567', externalRef: 'r-1', text: 'Hei'));
            $this->fail('No exception.');
        } catch (GatewayException $exception) {
            $this->assertSame($status, $exception->httpStatus);
            $this->assertSame($isTemporary, $exception->isTemporary);
            $this->assertSame($isUnknown, $exception->isOutcomeUnknown);
            $this->assertSame('The gateway says why', $exception->remoteMessage);
            $this->assertStringContainsString('The gateway says why', $exception->getMessage());
        }
    }

    public function testNoAnswerToASendIsAnUnknownOutcome(): void
    {
        $this->gateway->failWith(new NetworkException('timed out', new Request()));

        try {
            $this->client->send(SendRequest::sms(to: '0401234567', externalRef: 'r-1', text: 'Hei'));
            $this->fail('No exception.');
        } catch (GatewayException $exception) {
            $this->assertTrue($exception->isOutcomeUnknown);
            $this->assertTrue($exception->isTemporary);
            $this->assertSame(0, $exception->httpStatus);
        }
    }

    public function testAnAnswerWithoutAKnownResultIsAnUnknownOutcome(): void
    {
        $this->gateway->answer(202, ['result' => 'maybe']);

        $this->expectException(GatewayException::class);
        try {
            $this->client->send(SendRequest::sms(to: '0401234567', externalRef: 'r-1', text: 'Hei'));
        } catch (GatewayException $exception) {
            $this->assertTrue($exception->isOutcomeUnknown);
            throw $exception;
        }
    }

    public function testAMessageIsReadByItsUuid(): void
    {
        $sent = $this->client->send(SendRequest::sms(to: '0401234567', externalRef: 'r-1', text: 'Hei'));

        $message = $this->client->message((string)$sent->message->uuid);

        $this->assertSame('r-1', $message->externalRef);
        $this->assertSame('queued', $message->status);
    }

    public function testOnlyAUuidReachesTheMessagePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client->message('../suppressions');
    }

    public function testASuppressionCheckAsksByChannelAndAddress(): void
    {
        $this->gateway->suppressedIdentities = ['0401234567'];

        $this->assertTrue($this->client->checkSuppression('sms', '0401234567')->isSuppressed);
        $this->assertFalse($this->client->checkSuppression('sms', '0401234568')->isSuppressed);
        $this->assertSame(['channel' => 'sms', 'identity' => '0401234568'], $this->gateway->requests[1]['query']);
    }
}
