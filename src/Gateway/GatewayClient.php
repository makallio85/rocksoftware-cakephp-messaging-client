<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Gateway;

use Cake\Http\Client;
use Cake\Http\Client\Response;
use InvalidArgumentException;
use JsonException;
use RockSoftware\MessagingClient\Credentials\Credentials;
use RockSoftware\MessagingClient\MessagingClientPlugin;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;
use RockSoftware\MessagingClient\SendRequest;
use SensitiveParameter;
use Throwable;

/**
 * The messaging gateway's public API (`/public-api/v1`).
 *
 * The address comes from settings a person typed, so it is checked before any
 * request: https, a bare host name and nothing else. The token travels only in
 * the `Authorization` header, and redirects are never followed, so it cannot
 * be carried to another host.
 *
 * Most applications never use this class directly: `Messenger` records and
 * sends through it. It is public for the settings page and for an application
 * that needs a gateway call `Messenger` does not make.
 */
class GatewayClient
{
    public const API_PATH = '/public-api/v1/';

    /** The permissions the settings page expects a sending token to hold. */
    public const PERMISSION_STATUS_READ = 'system.status.read';

    public const PERMISSION_MESSAGE_SEND = 'message.send';

    /**
     * Characters a gateway URL may hold at all. Anything else (a backslash, `@`,
     * whitespace, `%`) could make the parsed host differ from the one the HTTP
     * client connects to.
     */
    private const URL_PATTERN = '#^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?/?$#D';

    private const HOST_LABEL_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/D';

    private const UUID_PATTERN = '/^[0-9a-fA-F-]{36}$/D';

    private string $apiRoot;

    private string $token;

    private Client $http;

    /**
     * @param string $baseUrl The gateway's address, such as `https://messaging.example.com`.
     * @param string $token The API token issued in the gateway console.
     * @param \Cake\Http\Client|null $http The HTTP client; null for the `httpClient` setting, else a new one.
     * @throws \InvalidArgumentException When the address is not a plain https host.
     */
    public function __construct(
        string $baseUrl,
        #[SensitiveParameter]
        string $token,
        ?Client $http = null,
    ) {
        $this->apiRoot = self::apiRoot($baseUrl);
        $this->token = $token;
        $configured = MessagingClientPlugin::setting('httpClient');
        $this->http = $http
            ?? ($configured instanceof Client ? $configured : new Client(['timeout' => 30, 'redirect' => false]));
    }

    /**
     * The client for stored or provided credentials.
     *
     * @param \RockSoftware\MessagingClient\Credentials\Credentials $credentials The gateway address and token.
     * @param \Cake\Http\Client|null $http The HTTP client.
     * @return self
     * @throws \InvalidArgumentException When the address is not a plain https host.
     */
    public static function fromCredentials(Credentials $credentials, ?Client $http = null): self
    {
        return new self($credentials->gatewayUrl, $credentials->token(), $http);
    }

    /**
     * The API root for a gateway address, or an exception that says what is
     * wrong with it.
     *
     * @param string $baseUrl The gateway's address.
     * @return string Such as `https://messaging.example.com/public-api/v1/`.
     * @throws \InvalidArgumentException
     */
    public static function apiRoot(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        if (!preg_match(self::URL_PATTERN, $baseUrl)) {
            throw new InvalidArgumentException(__d(
                'rock_software_messaging_client',
                'Enter the gateway as https:// and a host name only, such as https://messaging.example.com.',
            ));
        }
        $host = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
        $port = parse_url($baseUrl, PHP_URL_PORT);
        foreach (explode('.', $host) as $label) {
            if (!preg_match(self::HOST_LABEL_PATTERN, $label)) {
                throw new InvalidArgumentException(
                    __d('rock_software_messaging_client', '"{0}" is not a valid host name.', $host),
                );
            }
        }
        if (is_int($port) && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException(
                __d('rock_software_messaging_client', 'The port must be between 1 and 65535.'),
            );
        }

        return 'https://' . $host . (is_int($port) ? ':' . $port : '') . self::API_PATH;
    }

    /**
     * `GET /system/status`: whether the token works, what it may do, and
     * whether anything sent with it reaches a person. The gateway records
     * every call in its audit log.
     *
     * @return \RockSoftware\MessagingClient\Gateway\GatewayStatus
     * @throws \RockSoftware\MessagingClient\Gateway\GatewayException
     */
    public function status(): GatewayStatus
    {
        $body = $this->decode($this->get('system/status'), false);
        if (($body['authenticated'] ?? null) !== true) {
            throw new GatewayException(
                __d('rock_software_messaging_client', 'The messaging gateway did not confirm the API token.'),
                httpStatus: 200,
            );
        }
        $token = is_array($body['token'] ?? null) ? $body['token'] : [];
        $webhookEvents = $body['webhook_events'] ?? null;

        return new GatewayStatus(
            is_string($token['name'] ?? null) ? $token['name'] : '',
            // A token the gateway does not call live is treated as a test token.
            ($token['is_test'] ?? true) !== false,
            self::strings($body['permissions'] ?? []),
            ($body['sending_enabled'] ?? false) === true,
            is_array($webhookEvents) ? self::strings($webhookEvents) : null,
        );
    }

    /**
     * `GET /connections`: the identities the token may send as.
     *
     * @return list<\RockSoftware\MessagingClient\Gateway\GatewayConnection>
     * @throws \RockSoftware\MessagingClient\Gateway\GatewayException
     */
    public function connections(): array
    {
        $body = $this->decode($this->get('connections'), false);
        $rows = [];
        foreach (is_array($body['connections'] ?? null) ? $body['connections'] : [] as $row) {
            if (!is_array($row) || !is_string($row['code'] ?? null) || !is_string($row['channel'] ?? null)) {
                continue;
            }
            $rows[] = new GatewayConnection(
                $row['code'],
                $row['channel'],
                is_string($row['name'] ?? null) ? $row['name'] : '',
                is_string($row['identity'] ?? null) ? $row['identity'] : '',
                is_string($row['display_name'] ?? null) ? $row['display_name'] : '',
                ($row['is_test'] ?? false) === true,
                ($row['outbound_enabled'] ?? false) === true,
                ($row['is_owned'] ?? false) === true,
                ($row['inbound_enabled'] ?? false) === true,
            );
        }

        return $rows;
    }

    /**
     * `POST /messages`. Sending the same request again is safe: the gateway
     * answers a known `external_ref` with the message it already has.
     *
     * @param \RockSoftware\MessagingClient\SendRequest $request The message.
     * @return \RockSoftware\MessagingClient\Gateway\SendResult Queued, already accepted, or suppressed.
     * @throws \RockSoftware\MessagingClient\Gateway\GatewayException When it was refused or the outcome is unknown.
     */
    public function send(SendRequest $request): SendResult
    {
        try {
            $json = json_encode($request->toPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The message cannot be encoded as JSON.', 0, $exception);
        }
        try {
            $response = $this->http->post(
                $this->apiRoot . 'messages',
                $json,
                ['headers' => $this->headers() + ['Content-Type' => 'application/json'], 'redirect' => false],
            );
        } catch (Throwable $exception) {
            throw new GatewayException(
                __d(
                    'rock_software_messaging_client',
                    'The messaging gateway did not answer. Sending again with the same external_ref is safe.',
                ),
                isOutcomeUnknown: true,
                previous: $exception,
            );
        }

        $body = $response->getStatusCode() === 409 ? $response->getJson() : null;
        if (is_array($body) && ($body['result'] ?? null) === SendResult::RESULT_SUPPRESSED) {
            return self::sendResult($body);
        }

        return self::sendResult($this->decode($response, true));
    }

    /**
     * `GET /messages/{uuid}`: one of the application's messages.
     *
     * @param string $uuid The gateway's id of the message.
     * @return \RockSoftware\MessagingClient\Gateway\GatewayMessage
     * @throws \RockSoftware\MessagingClient\Gateway\GatewayException
     */
    public function message(string $uuid): GatewayMessage
    {
        if (!preg_match(self::UUID_PATTERN, $uuid)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a message uuid.', $uuid));
        }
        $body = $this->decode($this->get('messages/' . $uuid), false);

        return GatewayMessage::fromArray($body);
    }

    /**
     * `GET /suppressions/check`: whether an address may be messaged.
     *
     * @param string $channel `ChannelType::*`.
     * @param string $identity The address, as written.
     * @return \RockSoftware\MessagingClient\Gateway\SuppressionCheck
     * @throws \RockSoftware\MessagingClient\Gateway\GatewayException
     */
    public function checkSuppression(string $channel, string $identity): SuppressionCheck
    {
        if (!in_array($channel, ChannelType::VALUES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown messaging channel "%s".', $channel));
        }
        $body = $this->decode(
            $this->get('suppressions/check', ['channel' => $channel, 'identity' => $identity]),
            false,
        );

        return new SuppressionCheck(
            is_string($body['channel'] ?? null) ? $body['channel'] : $channel,
            is_string($body['identity'] ?? null) ? $body['identity'] : $identity,
            // An unclear answer counts as suppressed: a missed message is better than an unwanted one.
            ($body['suppressed'] ?? true) !== false,
        );
    }

    /**
     * Never print the token, even in a debug dump.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['apiRoot' => $this->apiRoot];
    }

    /**
     * @param string $path Path under the API root.
     * @param array<string, string> $query Query parameters.
     * @return \Cake\Http\Client\Response
     * @throws \RockSoftware\MessagingClient\Gateway\GatewayException
     */
    private function get(string $path, array $query = []): Response
    {
        try {
            return $this->http->get(
                $this->apiRoot . $path,
                $query,
                ['headers' => $this->headers(), 'redirect' => false],
            );
        } catch (Throwable $exception) {
            throw new GatewayException(
                __d('rock_software_messaging_client', 'The messaging gateway did not answer.'),
                previous: $exception,
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'];
    }

    /**
     * @param \Cake\Http\Client\Response $response The response.
     * @param bool $isWrite Whether the request could have changed something at the gateway.
     * @return array<string, mixed>
     * @throws \RockSoftware\MessagingClient\Gateway\GatewayException
     */
    private function decode(Response $response, bool $isWrite): array
    {
        $status = $response->getStatusCode();
        $body = $response->getJson();
        if ($response->isSuccess() && is_array($body)) {
            return $body;
        }
        if ($response->isSuccess()) {
            throw new GatewayException(
                __d('rock_software_messaging_client', 'The messaging gateway answered HTTP {0} without JSON.', $status),
                httpStatus: $status,
                isOutcomeUnknown: $isWrite,
            );
        }
        if ($response->isRedirect()) {
            throw new GatewayException(
                __d(
                    'rock_software_messaging_client',
                    'The messaging gateway answered with a redirect (HTTP {0}), which is never followed. '
                    . 'Check the gateway address.',
                    $status,
                ),
                httpStatus: $status,
            );
        }

        // Errors are {"error": {"message": "...", "code": 403}}.
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $remoteMessage = is_string($error['message'] ?? null) ? mb_substr($error['message'], 0, 500) : '';

        $message = match (true) {
            $status === 401 => __d(
                'rock_software_messaging_client',
                'The messaging gateway does not accept the API token (HTTP 401). '
                . 'Issue a new token in the gateway console.',
            ),
            $status === 403 => __d(
                'rock_software_messaging_client',
                'The messaging gateway refused the request (HTTP 403). '
                . 'Check the token\'s permissions and channels, and that its IP allowlist includes this server.',
            ),
            $status === 404 => __d(
                'rock_software_messaging_client',
                'The messaging gateway does not know that sender or message (HTTP 404).',
            ),
            $status === 429 => __d(
                'rock_software_messaging_client',
                'The messaging gateway asks to slow down: '
                . 'the token has reached its send quota or request rate (HTTP 429).',
            ),
            $status === 503 => __d(
                'rock_software_messaging_client',
                'The messaging gateway\'s API is switched off in its console (HTTP 503).',
            ),
            $status >= 500 => __d(
                'rock_software_messaging_client',
                'The messaging gateway failed (HTTP {0}).',
                $status,
            ),
            default => __d(
                'rock_software_messaging_client',
                'The messaging gateway refused the request (HTTP {0}).',
                $status,
            ),
        };
        if ($remoteMessage !== '') {
            $message .= ' ' . $remoteMessage;
        }

        throw new GatewayException(
            $message,
            httpStatus: $status,
            // A 5xx after a send may still have been recorded at the gateway;
            // 503 is the API's kill switch, answered before anything is stored.
            isOutcomeUnknown: $isWrite && $status >= 500 && $status !== 503,
            isTemporary: $status === 401 || $status === 429 || $status >= 500,
            remoteMessage: $remoteMessage,
        );
    }

    /**
     * @param array<string, mixed> $body The send response.
     * @return \RockSoftware\MessagingClient\Gateway\SendResult
     * @throws \RockSoftware\MessagingClient\Gateway\GatewayException
     */
    private static function sendResult(array $body): SendResult
    {
        $result = $body['result'] ?? null;
        $message = is_array($body['message'] ?? null) ? $body['message'] : [];
        if (!is_string($result) || !in_array($result, SendResult::RESULTS, true) || $message === []) {
            throw new GatewayException(
                __d(
                    'rock_software_messaging_client',
                    'The messaging gateway answered the send without a known result.',
                ),
                httpStatus: 200,
                isOutcomeUnknown: true,
            );
        }

        return new SendResult($result, GatewayMessage::fromArray($message), self::strings($body['ignored'] ?? []));
    }

    /**
     * @param mixed $values A JSON list.
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }
}
