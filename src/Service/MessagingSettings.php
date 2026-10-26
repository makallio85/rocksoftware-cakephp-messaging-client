<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Service;

use Cake\Http\Client;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Text;
use InvalidArgumentException;
use RockSoftware\MessagingClient\Credentials\CredentialsProviderInterface;
use RockSoftware\MessagingClient\Credentials\SettingsCredentialsProvider;
use RockSoftware\MessagingClient\Gateway\GatewayClient;
use RockSoftware\MessagingClient\Gateway\GatewayException;
use RockSoftware\MessagingClient\MessagingClientPlugin;
use RockSoftware\MessagingClient\Messenger;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessage;
use RockSoftware\MessagingClient\Model\Entity\Setting;
use RockSoftware\MessagingClient\Model\Table\SendersTable;
use RockSoftware\MessagingClient\Model\Table\SettingsTable;
use RockSoftware\MessagingClient\SendRequest;
use RockSoftware\Settings\Settings;
use RockSoftware\Settings\SettingsPlugin;
use SensitiveParameter;
use Throwable;

/**
 * What the settings page does: keep the gateway address, token and senders,
 * check the connection, send a test message, list the latest outbound
 * messages. The controller only routes to it.
 */
class MessagingSettings
{
    use LocatorAwareTrait;

    /** The prefix of every test message's `external_ref`. */
    public const TEST_EXTERNAL_REF_PREFIX = 'messaging-client-test-';

    private CredentialsProviderInterface $credentialsProvider;

    private ?Client $http;

    /**
     * @param \RockSoftware\MessagingClient\Credentials\CredentialsProviderInterface|null $credentialsProvider
     *   Null for the settings page's own (tests pass their own).
     * @param \Cake\Http\Client|null $http The HTTP client; null for `httpClient` in the settings.
     */
    public function __construct(?CredentialsProviderInterface $credentialsProvider = null, ?Client $http = null)
    {
        $this->credentialsProvider = $credentialsProvider ?? new SettingsCredentialsProvider();
        $this->http = $http;
    }

    /**
     * Whether a gateway address and a token are set.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        try {
            return $this->credentialsProvider->credentials() !== null;
        } catch (Throwable) {
            // Stored but unreadable (a changed salt): set, and the check says what is wrong.
            return true;
        }
    }

    /**
     * The settings row, or a new empty one.
     *
     * @return \RockSoftware\MessagingClient\Model\Entity\Setting
     */
    public function setting(): Setting
    {
        return $this->settingsTable()->currentOrNew();
    }

    /**
     * Saves the address and the senders. A token typed in replaces the stored
     * one; an empty token field keeps it, so correcting the address never
     * wipes it; `remove_api_token` removes it.
     *
     * @param array<string, mixed> $data `gateway_url`, `api_token`, `remove_api_token`.
     * @param list<string>|null $changed Set to the names of the fields that changed (`api_token` for the token).
     * @return \RockSoftware\MessagingClient\Model\Entity\Setting The row; with errors when nothing was saved.
     */
    public function save(#[SensitiveParameter] array $data, ?array &$changed = null): Setting
    {
        $changed = [];
        $table = $this->settingsTable();
        $setting = $table->currentOrNew();
        $before = $setting->extract(['gateway_url', 'api_token']);
        $fields = [];
        foreach (['gateway_url'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string)$data[$field]);
                $fields[$field] = $value === '' ? null : $value;
            }
        }
        $setting = $table->patchEntity($setting, $fields);

        $token = trim((string)($data['api_token'] ?? ''));
        if (!empty($data['remove_api_token'])) {
            $setting->api_token = null;
        } elseif ($token !== '') {
            $setting->api_token = SecretCipher::encrypt($token);
        }

        if ($table->save($setting) === false) {
            return $setting;
        }
        foreach ($before as $field => $value) {
            if ($setting->get($field) !== $value) {
                $changed[] = $field;
            }
        }

        return $setting;
    }

    /**
     * "Test connection": asks the gateway who the token is and what it may
     * send as. Sends nothing.
     *
     * @return \RockSoftware\MessagingClient\Service\ConnectionCheck
     */
    public function checkConnection(): ConnectionCheck
    {
        $check = $this->runConnectionCheck();
        if ($check->isConfigured) {
            $table = $this->settingsTable();
            $setting = $table->currentOrNew();
            $setting->patch([
                'checked_at' => DateTime::now(),
                'is_connection_ok' => $check->isConnected() && $check->missingPermissions() === [],
                'token_name' => $check->status === null ? null : mb_substr($check->status->tokenName, 0, 150),
                'is_test_token' => $check->status?->isTestToken,
                'is_gateway_sending_enabled' => $check->status?->isSendingEnabled,
                'check_error' => $check->error === null ? null : mb_substr($check->error, 0, 500),
            ], ['guard' => false]);
            $table->save($setting);
        }

        return $check;
    }

    /**
     * @return \RockSoftware\MessagingClient\Service\ConnectionCheck
     */
    private function runConnectionCheck(): ConnectionCheck
    {
        $isDeliveryEnabled = array_map(
            fn(ChannelSwitch $switch): bool => $switch->isOn(),
            DeliveryGate::switches(),
        );
        try {
            $client = $this->client();
        } catch (Throwable $exception) {
            return new ConnectionCheck($isDeliveryEnabled, true, $exception->getMessage());
        }
        if ($client === null) {
            return new ConnectionCheck($isDeliveryEnabled, false, __d(
                'rock_software_messaging_client',
                'Enter the gateway address and an API token first.',
            ));
        }

        try {
            $status = $client->status();
            $connections = $status->hasPermission(GatewayClient::PERMISSION_STATUS_READ) ? $client->connections() : [];
            if ($status->hasPermission(GatewayClient::PERMISSION_STATUS_READ)) {
                $this->sendersTable()->refresh($connections);
            }
        } catch (GatewayException $exception) {
            return new ConnectionCheck($isDeliveryEnabled, true, $exception->getMessage());
        }

        return new ConnectionCheck($isDeliveryEnabled, true, null, $status, $connections);
    }

    /**
     * Refreshes the senders from the gateway's `GET /connections`.
     *
     * @return void
     * @throws \RockSoftware\MessagingClient\Gateway\GatewayException When the gateway does not answer.
     * @throws \InvalidArgumentException When the gateway is not set up.
     */
    public function refreshSenders(): void
    {
        $client = $this->client() ?? throw new InvalidArgumentException(__d(
            'rock_software_messaging_client',
            'Enter the gateway address and an API token first.',
        ));
        $this->sendersTable()->refresh($client->connections());
    }

    /**
     * The senders, by channel (`ChannelType::*`).
     *
     * @return array<string, list<\RockSoftware\MessagingClient\Model\Entity\Sender>>
     */
    public function senders(): array
    {
        $this->sendersTable()->adoptLegacyAccounts();

        return $this->sendersTable()->byChannel();
    }

    /**
     * Saves which senders are enabled and each channel's default.
     *
     * @param array<string, mixed> $data `enabled` (list of enabled codes), `default` (channel => code, or
     *   empty for the gateway's default) and optionally `channel`, limiting the save to that channel's senders.
     * @return list<string> The codes whose state changed.
     * @throws \InvalidArgumentException When a default names an unknown sender, one on another channel, or one
     *   that is not enabled.
     */
    public function saveSenders(array $data): array
    {
        $table = $this->sendersTable();
        $enabled = array_map('strval', (array)($data['enabled'] ?? []));
        $defaults = (array)($data['default'] ?? []);
        $byChannel = $table->byChannel();
        $only = (string)($data['channel'] ?? '');
        if ($only !== '') {
            $byChannel = array_intersect_key($byChannel, [$only => true]);
            $defaults = array_intersect_key($defaults, [$only => true]) + [$only => ''];
        }
        $before = [];
        foreach ($byChannel as $senders) {
            foreach ($senders as $sender) {
                $before[$sender->code] = [$sender->is_enabled, $sender->is_default];
            }
        }
        foreach ($defaults as $channel => $code) {
            $code = (string)$code;
            if ($code === '') {
                continue;
            }
            $codes = array_map(fn($sender): string => $sender->code, $byChannel[(string)$channel] ?? []);
            if (!in_array($code, $codes, true) || !in_array($code, $enabled, true)) {
                throw new InvalidArgumentException(__d(
                    'rock_software_messaging_client',
                    'The default sender must be an enabled sender of its channel.',
                ));
            }
        }

        $table->getConnection()->transactional(function () use ($table, $byChannel, $enabled, $defaults): void {
            foreach ($byChannel as $channel => $senders) {
                foreach ($senders as $sender) {
                    $table->setEnabled($sender->code, in_array($sender->code, $enabled, true));
                }
                $default = (string)($defaults[$channel] ?? '');
                if ($default === '') {
                    $table->clearDefault($channel);
                } else {
                    $table->setDefault($default);
                }
            }
        });

        $changed = [];
        foreach ($table->byChannel() as $senders) {
            foreach ($senders as $sender) {
                if (($before[$sender->code] ?? null) !== [$sender->is_enabled, $sender->is_default]) {
                    $changed[] = $sender->code;
                }
            }
        }

        return $changed;
    }

    /**
     * Sends a test message through `Messenger`, so it is logged, gated and
     * retried like any other. Without a channel, an address with `@` gets an
     * email and anything else an SMS.
     *
     * @param string $recipient An email address or a phone number.
     * @param string|null $channel `ChannelType::*`, or null to tell from the recipient.
     * @param string|null $sender A sender's code, or null for the channel's default.
     * @return \RockSoftware\MessagingClient\Model\Entity\OutboundMessage
     * @throws \InvalidArgumentException When there is no recipient, or the sender may not be used.
     */
    public function sendTestMessage(string $recipient, ?string $channel = null, ?string $sender = null): OutboundMessage
    {
        $sender = $sender === null || trim($sender) === '' ? null : trim($sender);
        $recipient = trim($recipient);
        if ($recipient === '') {
            throw new InvalidArgumentException(
                __d('rock_software_messaging_client', 'Enter the address the test message goes to.'),
            );
        }
        $appName = trim((string)Settings::get(SettingsPlugin::APPLICATION_NAME));
        $appName = $appName !== '' ? $appName : __d('rock_software_messaging_client', 'this application');
        $externalRef = self::TEST_EXTERNAL_REF_PREFIX . Text::uuid();
        $context = ['purpose' => 'messaging_client_test'];

        $channel = $channel === null || $channel === ''
            ? (str_contains($recipient, '@') ? ChannelType::EMAIL : ChannelType::SMS)
            : $channel;
        $request = $channel === ChannelType::EMAIL
            ? SendRequest::email(
                to: $recipient,
                externalRef: $externalRef,
                subject: __d('rock_software_messaging_client', 'Test message from {0}', $appName),
                text: __d(
                    'rock_software_messaging_client',
                    'This is a test message from {0}. If you are reading it, {0} reaches the messaging gateway '
                    . 'and the gateway delivers its messages.',
                    $appName,
                ),
                sender: $sender,
                context: $context,
                isAutoSubmitted: true,
            )
            : SendRequest::sms(
                to: $recipient,
                externalRef: $externalRef,
                text: __d(
                    'rock_software_messaging_client',
                    'Test message from {0}: it reaches you through the messaging gateway.',
                    $appName,
                ),
                sender: $sender,
                context: $context,
            );

        return (new Messenger($this->credentialsProvider, $this->http))->send($request);
    }

    /**
     * Switches sending on a channel on or off in this application. On binds
     * the switch to this application's address (`App.fullBaseUrl`).
     *
     * @param string $channel `ChannelType::*`.
     * @param bool $isOn On or off.
     * @param string|null $changedBy Who switched it (`Actor::label()`).
     * @return \RockSoftware\MessagingClient\Service\ChannelSwitch The switch as it now stands.
     * @throws \InvalidArgumentException For an unknown channel, or switching on without `App.fullBaseUrl`.
     */
    public function switchSending(string $channel, bool $isOn, ?string $changedBy): ChannelSwitch
    {
        if (!in_array($channel, ChannelType::VALUES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown messaging channel "%s".', $channel));
        }
        $url = AppBaseUrl::current();
        if ($isOn && $url === null) {
            throw new InvalidArgumentException(__d(
                'rock_software_messaging_client',
                'Sending cannot be switched on: the application\'s address (App.fullBaseUrl) is not configured.',
            ));
        }
        /** @var \RockSoftware\MessagingClient\Model\Table\ChannelSettingsTable $table */
        $table = $this->fetchTable('RockSoftware/MessagingClient.ChannelSettings');
        $table->switchSending($channel, $isOn, $url, $changedBy);

        return DeliveryGate::switchOf($channel);
    }

    /**
     * The latest outbound messages, newest first.
     *
     * @return list<\RockSoftware\MessagingClient\Model\Entity\OutboundMessage>
     */
    public function latestMessages(): array
    {
        /** @var list<\RockSoftware\MessagingClient\Model\Entity\OutboundMessage> */
        return $this->fetchTable('RockSoftware/MessagingClient.OutboundMessages')
            ->find('latest', limit: (int)MessagingClientPlugin::setting('latestMessagesLimit'))
            ->all()
            ->toList();
    }

    /**
     * @return \RockSoftware\MessagingClient\Gateway\GatewayClient|null Null when not set up.
     */
    private function client(): ?GatewayClient
    {
        $credentials = $this->credentialsProvider->credentials();

        return $credentials === null ? null : GatewayClient::fromCredentials($credentials, $this->http);
    }

    /**
     * @return \RockSoftware\MessagingClient\Model\Table\SendersTable
     */
    private function sendersTable(): SendersTable
    {
        /** @var \RockSoftware\MessagingClient\Model\Table\SendersTable */
        return $this->fetchTable('RockSoftware/MessagingClient.Senders');
    }

    /**
     * @return \RockSoftware\MessagingClient\Model\Table\SettingsTable
     */
    private function settingsTable(): SettingsTable
    {
        /** @var \RockSoftware\MessagingClient\Model\Table\SettingsTable */
        return $this->fetchTable('RockSoftware/MessagingClient.Settings');
    }
}
