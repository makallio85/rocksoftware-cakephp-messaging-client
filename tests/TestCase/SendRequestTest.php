<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase;

use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RockSoftware\MessagingClient\SendRequest;

class SendRequestTest extends TestCase
{
    public function testAnEmailBecomesTheGatewaysPayload(): void
    {
        $request = SendRequest::email(
            to: ['liisa@example.test', 'matti@example.test'],
            externalRef: 'change-request-17',
            subject: 'A change request waits',
            text: 'Open it.',
            html: '<p>Open it.</p>',
            cc: 'boss@example.test',
            replyTo: 'app@example.test',
            fromName: 'Example App',
            context: ['change_request_id' => 17],
            isAutoSubmitted: true,
            validFor: 3600,
        );

        $this->assertSame([
            'channel' => 'email',
            'to' => ['liisa@example.test', 'matti@example.test'],
            'external_ref' => 'change-request-17',
            'priority' => 'transactional',
            'subject' => 'A change request waits',
            'text' => 'Open it.',
            'html' => '<p>Open it.</p>',
            'from_name' => 'Example App',
            'cc' => ['boss@example.test'],
            'reply_to' => ['app@example.test'],
            'context' => ['change_request_id' => 17],
            'auto_submitted' => true,
            'valid_for' => 3600,
        ], $request->toPayload());
    }

    public function testAPayloadMakesTheSameRequestAgain(): void
    {
        $request = SendRequest::sms(
            to: '040 123 4567',
            externalRef: 'reminder-1',
            text: 'Huoltosi on varattu.',
            sender: 'app-sms',
            priority: SendRequest::PRIORITY_BULK,
            expiresAt: new DateTime('2026-10-24 18:00:00', 'Europe/Helsinki'),
        );

        $payload = $request->toPayload();
        $this->assertSame('2026-10-24T15:00:00+00:00', $payload['expires_at']);
        $this->assertSame($payload, SendRequest::fromPayload($payload)->toPayload());
    }

    public function testValidForBecomesADeadlineKeptAcrossRetries(): void
    {
        $request = SendRequest::sms(to: '0401234567', externalRef: 'r', text: 'x', validFor: 600)
            ->withDeadlineFrom(new DateTime('2026-10-24 09:00:00', 'UTC'));

        $this->assertNull($request->validFor);
        $this->assertSame('2026-10-24T09:10:00+00:00', $request->toPayload()['expires_at']);
        $this->assertArrayNotHasKey('valid_for', $request->toPayload());
    }

    public function testADefaultSenderNeverReplacesTheRequestsOwn(): void
    {
        $own = SendRequest::sms(to: '0401234567', externalRef: 'r', text: 'x', sender: 'own');
        $none = SendRequest::sms(to: '0401234567', externalRef: 'r', text: 'x');

        $this->assertSame('own', $own->withDefaultSender('default')->sender);
        $this->assertSame('default', $none->withDefaultSender('default')->sender);
        $this->assertSame('default', $none->withDefaultSender('default')->toPayload()['account']);
        $this->assertNull($none->withDefaultSender(null)->sender);
    }

    /**
     * @return array<string, array{0: callable(): mixed, 1: string}>
     */
    public static function refusedRequests(): array
    {
        return [
            'unknown channel' => [
                fn() => new SendRequest('whatsapp', '0401234567', 'r', 'x'),
                'Unknown messaging channel',
            ],
            'unknown priority' => [
                fn() => SendRequest::sms(to: '0401234567', externalRef: 'r', text: 'x', priority: 'urgent'),
                'Unknown messaging priority',
            ],
            'no external_ref' => [fn() => SendRequest::sms(to: '0401234567', externalRef: ' ', text: 'x'), 'external_ref'],
            'external_ref too long' => [
                fn() => SendRequest::sms(to: '0401234567', externalRef: str_repeat('a', 192), text: 'x'),
                'external_ref',
            ],
            'no recipient' => [fn() => SendRequest::sms(to: '  ', externalRef: 'r', text: 'x'), 'recipient'],
            'no body' => [fn() => SendRequest::email(to: 'a@example.test', externalRef: 'r', subject: 's'), 'body'],
            'body too large' => [
                fn() => SendRequest::email(
                    to: 'a@example.test',
                    externalRef: 'r',
                    subject: 's',
                    text: str_repeat('a', 262145),
                ),
                '256 KB',
            ],
            'sms too long' => [
                fn() => SendRequest::sms(to: '0401234567', externalRef: 'r', text: str_repeat('a', 1601)),
                '1600',
            ],
            'subject on sms' => [
                fn() => SendRequest::sms(to: '0401234567', externalRef: 'r', text: 'x', subject: 's'),
                'email only',
            ],
            'cc on sms' => [
                fn() => SendRequest::sms(to: '0401234567', externalRef: 'r', text: 'x', cc: 'a@example.test'),
                'email only',
            ],
            'two sms recipients' => [
                fn() => new SendRequest('sms', ['0401234567', '0401234568'], 'r', 'x'),
                'exactly one recipient',
            ],
            'too many email recipients' => [
                fn() => SendRequest::email(
                    to: array_map(fn(int $i): string => "p{$i}@example.test", range(1, 51)),
                    externalRef: 'r',
                    subject: 's',
                    text: 'x',
                ),
                'at most 50',
            ],
            'context is a list' => [
                fn() => SendRequest::sms(to: '0401234567', externalRef: 'r', text: 'x', context: [1, 2]),
                'JSON object',
            ],
            'both deadlines' => [
                fn() => SendRequest::sms(
                    to: '0401234567',
                    externalRef: 'r',
                    text: 'x',
                    validFor: 60,
                    expiresAt: new DateTime('+1 hour'),
                ),
                'not both',
            ],
            'valid_for out of range' => [
                fn() => SendRequest::sms(to: '0401234567', externalRef: 'r', text: 'x', validFor: 2592001),
                'validFor',
            ],
        ];
    }

    /**
     * @param callable(): mixed $build Builds the request.
     * @param string $expected Part of the error.
     */
    #[DataProvider('refusedRequests')]
    public function testWhatTheGatewayWouldRefuseIsRefusedFirst(callable $build, string $expected): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);
        $build();
    }
}
