<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\TestSuite;

use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\Utility\Text;
use Psr\Http\Message\RequestInterface;
use RockSoftware\MessagingClient\Gateway\GatewayClient;
use RuntimeException;
use Throwable;

/**
 * A messaging gateway that never reaches the network, for tests: an HTTP
 * client that records every request and answers it the way the gateway's
 * public API does.
 *
 * Unless an answer is queued, it behaves like a gateway with a live token
 * that may send email and SMS: `POST /messages` queues a message (`202`),
 * answers a known `external_ref` with `200 already_accepted`, and an address
 * in `suppressedIdentities` with `409 suppressed`; `GET /system/status`,
 * `GET /connections`, `GET /messages/{uuid}` and `GET /suppressions/check`
 * answer from `status`, `connections` and what it has accepted.
 *
 * `answer()`, `refuse()` and `failWith()` queue the next answers instead, in
 * order: a refusal, a timeout, a 5xx.
 *
 * It answers for any gateway address, so an application's own credentials
 * provider works with it. Use it through `MessagingTestTrait::useFakeGateway()`.
 */
class FakeGateway extends Client
{
    /** The address the fake answers on. */
    public const BASE_URL = 'https://messaging.example.test';

    /** The token the trait's credentials carry. */
    public const TOKEN = 'fake-gateway-token';

    /**
     * Every request, in order: `method`, `path` (under `/public-api/v1/`),
     * `url`, `headers`, `query`, `body` (decoded JSON, or null).
     *
     * @var list<array{method: string, path: string, url: string, headers: array<string, string>, query: array<string, mixed>, body: mixed}>
     */
    public array $requests = [];

    /**
     * What `GET /system/status` answers.
     *
     * @var array<string, mixed>
     */
    public array $status = [
        'authenticated' => true,
        'token' => ['name' => 'test-app', 'is_test' => false],
        'permissions' => ['system.status.read', 'message.send', 'message.read'],
        'sending_enabled' => true,
        'webhook_events' => [],
        'time' => '2026-10-24T09:00:00+00:00',
    ];

    /**
     * What `GET /connections` lists.
     *
     * @var list<array<string, mixed>>
     */
    public array $connections = [
        [
            'code' => 'test-app-email',
            'channel' => 'email',
            'name' => 'Test app email',
            'identity' => 'app@example.test',
            'display_name' => 'Test App',
            'is_test' => false,
            'outbound_enabled' => true,
            'is_owned' => true,
            'inbound_enabled' => false,
        ],
        [
            'code' => 'test-app-sms',
            'channel' => 'sms',
            'name' => 'Test app SMS',
            'identity' => '+358401234567',
            'display_name' => 'Test App',
            'is_test' => false,
            'outbound_enabled' => true,
            'is_owned' => true,
            'inbound_enabled' => false,
        ],
    ];

    /**
     * Addresses the gateway has blocked, as written in the send.
     *
     * @var list<string>
     */
    public array $suppressedIdentities = [];

    /**
     * Whether accepted messages are trapped (`is_test`), as with a test token.
     */
    public bool $isTrapping = false;

    /**
     * Messages the gateway holds, by `external_ref`.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $messages = [];

    /**
     * @var list<\Cake\Http\Client\Response|\Throwable>
     */
    private array $queue = [];

    /**
     * A client that never follows a redirect, as the gateway client's own.
     */
    public function __construct()
    {
        parent::__construct(['redirect' => false]);
    }

    /**
     * Queues the next answer.
     *
     * @param int $status HTTP status.
     * @param array<string, mixed>|string|null $body JSON body as an array, or a raw body.
     * @param list<string> $headers Further headers (`Location: …`).
     * @return $this
     */
    public function answer(int $status, array|string|null $body = null, array $headers = [])
    {
        $raw = is_array($body) ? (string)json_encode($body) : (string)$body;
        $this->queue[] = new Response(
            array_merge([sprintf('HTTP/1.1 %d Status', $status), 'Content-Type: application/json'], $headers),
            $raw,
        );

        return $this;
    }

    /**
     * Queues a refusal in the gateway's error shape.
     *
     * @param int $status HTTP status (403, 404, 422, 429, 500…).
     * @param string $message The gateway's message.
     * @return $this
     */
    public function refuse(int $status, string $message = 'Refused')
    {
        return $this->answer($status, ['error' => ['message' => $message, 'code' => $status]]);
    }

    /**
     * Queues a transport failure: no answer at all.
     *
     * @param \Throwable $exception What the transport throws.
     * @return $this
     */
    public function failWith(Throwable $exception)
    {
        $this->queue[] = $exception;

        return $this;
    }

    /**
     * Moves an accepted message on, as the gateway's worker and the
     * provider's receipts would: `GET /messages/{uuid}` answers with it.
     *
     * @param string $externalRef The message's `external_ref`.
     * @param string $status `GatewayMessage::STATUS_*` (`sent`, `delivered`, `failed`…).
     * @param string|null $errorCode The gateway's `error.code` on a failure (`bounced`, `expired`…).
     * @param string|null $errorMessage Its `error.message`.
     * @return $this
     */
    public function report(string $externalRef, string $status, ?string $errorCode = null, ?string $errorMessage = null)
    {
        if (!isset($this->messages[$externalRef])) {
            throw new RuntimeException(sprintf('The fake gateway has no message %s.', $externalRef));
        }
        $this->messages[$externalRef]['status'] = $status;
        $this->messages[$externalRef]['error'] = $errorCode === null && $errorMessage === null
            ? null
            : ['code' => $errorCode, 'message' => $errorMessage];
        if ($status === 'delivered') {
            $this->messages[$externalRef]['timestamps']['delivered_at'] = '2026-10-24T09:05:00+00:00';
        }

        return $this;
    }

    /**
     * The bodies of every `POST /messages`, retries included.
     *
     * @return list<array<string, mixed>>
     */
    public function sendRequests(): array
    {
        $bodies = [];
        foreach ($this->requests as $request) {
            if ($request['method'] === 'POST' && $request['path'] === 'messages' && is_array($request['body'])) {
                $bodies[] = $request['body'];
            }
        }

        return $bodies;
    }

    /**
     * The messages the gateway accepted, once each: what would go out.
     *
     * @return list<array<string, mixed>>
     */
    public function acceptedMessages(): array
    {
        return array_values(array_map(
            fn(array $message): array => $message['request'],
            array_filter($this->messages, fn(array $message): bool => $message['status'] !== 'suppressed'),
        ));
    }

    /**
     * @inheritDoc
     */
    protected function _sendRequest(RequestInterface $request, array $options): Response
    {
        $url = (string)$request->getUri();
        // Whatever host the credentials name: the fake answers for every one.
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, GatewayClient::API_PATH)) {
            $path = substr($path, strlen(GatewayClient::API_PATH));
        }
        parse_str((string)$request->getUri()->getQuery(), $query);
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $body = json_decode((string)$request->getBody(), true);
        $this->requests[] = [
            'method' => $request->getMethod(),
            'path' => $path,
            'url' => $url,
            'headers' => $headers,
            'query' => $query,
            'body' => $body,
        ];

        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }
        if ($next instanceof Response) {
            return $next;
        }

        return $this->emulate($request->getMethod(), $path, $query, is_array($body) ? $body : []);
    }

    /**
     * @param string $method The method.
     * @param string $path The path under the API root.
     * @param array<string, mixed> $query The query.
     * @param array<string, mixed> $body The JSON body.
     * @return \Cake\Http\Client\Response
     */
    private function emulate(string $method, string $path, array $query, array $body): Response
    {
        if ($method === 'GET' && $path === 'system/status') {
            return $this->json(200, $this->status);
        }
        if ($method === 'GET' && $path === 'connections') {
            return $this->json(200, [
                'connections' => $this->connections,
                'meta' => ['count' => count($this->connections)],
            ]);
        }
        if ($method === 'POST' && $path === 'messages') {
            return $this->accept($body);
        }
        if ($method === 'GET' && str_starts_with($path, 'messages/')) {
            foreach ($this->messages as $message) {
                if ($message['uuid'] === substr($path, 9)) {
                    unset($message['request']);

                    return $this->json(200, $message);
                }
            }

            return $this->json(404, ['error' => ['message' => 'No such message', 'code' => 404]]);
        }
        if ($method === 'GET' && $path === 'suppressions/check') {
            $identity = (string)($query['identity'] ?? '');
            $isSuppressed = in_array($identity, $this->suppressedIdentities, true);

            return $this->json(200, [
                'channel' => $query['channel'] ?? null,
                'identity' => $identity,
                'suppressed' => $isSuppressed,
                'scope' => $isSuppressed ? 'all' : null,
            ]);
        }

        return $this->json(404, ['error' => ['message' => 'Not found', 'code' => 404]]);
    }

    /**
     * @param array<string, mixed> $body The send.
     * @return \Cake\Http\Client\Response
     */
    private function accept(array $body): Response
    {
        $ref = (string)($body['external_ref'] ?? '');
        if ($ref !== '' && isset($this->messages[$ref])) {
            $message = $this->messages[$ref];
            unset($message['request']);

            return $this->json(200, ['result' => 'already_accepted', 'message' => $message, 'ignored' => []]);
        }
        $to = $body['to'] ?? '';
        $first = is_array($to) ? (string)($to[0] ?? '') : (string)$to;
        $isSuppressed = in_array($first, $this->suppressedIdentities, true);
        $message = [
            'uuid' => Text::uuid(),
            'channel' => $body['channel'] ?? null,
            'direction' => 'outbound',
            'status' => $isSuppressed ? 'suppressed' : 'queued',
            'priority' => $body['priority'] ?? 'transactional',
            'account' => $body['account'] ?? null,
            'to' => $first,
            'subject' => $body['subject'] ?? null,
            'external_ref' => $ref === '' ? null : $ref,
            'context' => $body['context'] ?? (object)[],
            'is_test' => $this->isTrapping,
            'attempts' => 0,
            'error' => null,
        ];
        if ($ref !== '') {
            $this->messages[$ref] = $message + ['request' => $body];
        }
        if ($isSuppressed) {
            return $this->json(409, ['result' => 'suppressed', 'message' => $message, 'ignored' => []]);
        }

        return $this->json(202, ['result' => 'queued', 'message' => $message, 'ignored' => [], 'sms' => null]);
    }

    /**
     * @param int $status HTTP status.
     * @param array<string, mixed> $body The body.
     * @return \Cake\Http\Client\Response
     */
    private function json(int $status, array $body): Response
    {
        return new Response(
            [sprintf('HTTP/1.1 %d Status', $status), 'Content-Type: application/json'],
            (string)json_encode($body),
        );
    }
}
