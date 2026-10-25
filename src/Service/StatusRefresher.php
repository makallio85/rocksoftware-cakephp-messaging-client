<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Service;

use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Http\Client;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use RockSoftware\MessagingClient\Credentials\CredentialsProviderInterface;
use RockSoftware\MessagingClient\Credentials\SettingsCredentialsProvider;
use RockSoftware\MessagingClient\Gateway\GatewayClient;
use RockSoftware\MessagingClient\Gateway\GatewayException;
use RockSoftware\MessagingClient\Gateway\GatewayMessage;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessage;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessageStatusType;
use RockSoftware\MessagingClient\Model\Table\OutboundMessagesTable;
use Throwable;

/**
 * Asks the gateway what became of the messages it accepted
 * (`GET /messages/{uuid}`) and records the answer: `SENT`, `DELIVERED`,
 * `TRAPPED`, `CANCELLED`, or `FAILED` with the gateway's reason (a bounce,
 * an expired message, an unknown number).
 *
 * Asked with a backoff (`nextCheck()`), and no more once the gateway
 * reports a final status, once an email is `sent` (email never reports
 * delivery, only a later bounce), or once the message is older than
 * `MAX_AGE_HOURS`. Each change fires `RockSoftwareMessagingClient.statusChanged`
 * (`outboundMessage`, `previousStatus`, `status`). Run by
 * `bin/cake messaging_client deliver`; the token needs `message.read`.
 */
class StatusRefresher
{
    use LocatorAwareTrait;

    public const EVENT_STATUS_CHANGED = 'RockSoftwareMessagingClient.statusChanged';

    /** Seconds after acceptance before the first question. */
    public const FIRST_CHECK_DELAY = 60;

    /** The shortest and the longest wait between two questions, in seconds. */
    public const MIN_CHECK_DELAY = 120;

    public const MAX_CHECK_DELAY = 12 * 3600;

    /** After this, a message is asked about no more. */
    public const MAX_AGE_HOURS = 72;

    /**
     * The gateway's statuses this plugin records, and as what.
     *
     * @var array<string, string>
     */
    private const STATUS_MAP = [
        GatewayMessage::STATUS_SENT => OutboundMessageStatusType::SENT,
        GatewayMessage::STATUS_DELIVERED => OutboundMessageStatusType::DELIVERED,
        GatewayMessage::STATUS_FAILED => OutboundMessageStatusType::FAILED,
        GatewayMessage::STATUS_TRAPPED => OutboundMessageStatusType::TRAPPED,
        GatewayMessage::STATUS_SUPPRESSED => OutboundMessageStatusType::SUPPRESSED,
        GatewayMessage::STATUS_CANCELLED => OutboundMessageStatusType::CANCELLED,
    ];

    /**
     * The gateway's statuses nothing changes after.
     *
     * @var list<string>
     */
    private const GATEWAY_FINAL = [
        GatewayMessage::STATUS_DELIVERED,
        GatewayMessage::STATUS_FAILED,
        GatewayMessage::STATUS_TRAPPED,
        GatewayMessage::STATUS_SUPPRESSED,
        GatewayMessage::STATUS_CANCELLED,
    ];

    /**
     * @param \RockSoftware\MessagingClient\Credentials\CredentialsProviderInterface|null $credentialsProvider
     *   Null for the settings page's own (tests pass their own).
     * @param \Cake\Http\Client|null $http The HTTP client; null for `httpClient` in the settings.
     */
    public function __construct(
        private ?CredentialsProviderInterface $credentialsProvider = null,
        private ?Client $http = null,
    ) {
    }

    /**
     * Asks about every accepted message whose next question is due, up to `$limit`.
     *
     * @param int $limit How many.
     * @return int How many changed status.
     */
    public function refreshDue(int $limit = 50): int
    {
        $table = $this->outboundMessages();
        $now = DateTime::now();
        $due = $table->find('withTypes')
            ->where([
                $table->aliasField('outbound_message_status_type_id') . ' IN' => array_map(
                    fn(string $status): int => $table->OutboundMessageStatusTypes->idFor($status),
                    OutboundMessageStatusType::AWAITING_GATEWAY,
                ),
                $table->aliasField('next_status_check_at') . ' <=' => $now,
                $table->aliasField('gateway_message_uuid') . ' IS NOT' => null,
            ])
            ->orderBy([$table->aliasField('next_status_check_at') => 'ASC'])
            ->limit($limit)
            ->all()
            ->toList();
        if ($due === []) {
            return 0;
        }

        try {
            $credentials = ($this->credentialsProvider ?? new SettingsCredentialsProvider())->credentials();
            $client = $credentials === null ? null : GatewayClient::fromCredentials($credentials, $this->http);
        } catch (Throwable $exception) {
            Log::warning('Messaging: cannot ask the gateway for message statuses: ' . $exception->getMessage());

            return 0;
        }
        if ($client === null) {
            return 0;
        }

        $changed = 0;
        foreach ($due as $message) {
            /** @var \RockSoftware\MessagingClient\Model\Entity\OutboundMessage $message */
            if ($this->refresh($client, $message, $now)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * @param \RockSoftware\MessagingClient\Gateway\GatewayClient $client The client.
     * @param \RockSoftware\MessagingClient\Model\Entity\OutboundMessage $message An accepted message.
     * @param \Cake\I18n\DateTime $now Now.
     * @return bool Whether its status changed.
     */
    private function refresh(GatewayClient $client, OutboundMessage $message, DateTime $now): bool
    {
        $table = $this->outboundMessages();
        $previous = (string)$message->statusValue();
        try {
            $remote = $client->message((string)$message->gateway_message_uuid);
        } catch (GatewayException $exception) {
            // A refusal (404: gone, or a test token reading a live message; 403: no
            // message.read) will not change by asking again; anything else might.
            $message->next_status_check_at = $exception->isTemporary
                ? $this->nextCheck($message, $now)
                : null;
            $message->status_checked_at = $now;
            $table->saveOrFail($message);

            return false;
        }

        $status = self::STATUS_MAP[(string)$remote->status] ?? $previous;
        $isFinal = in_array($remote->status, self::GATEWAY_FINAL, true)
            || ($remote->status === GatewayMessage::STATUS_SENT && $message->channelValue() === ChannelType::EMAIL);
        $patch = [
            'outbound_message_status_type_id' => $table->OutboundMessageStatusTypes->idFor($status),
            'status_checked_at' => $now,
            'is_test' => $message->is_test || $remote->isTest,
            'next_status_check_at' => $isFinal ? null : $this->nextCheck($message, $now),
        ];
        if ($status === OutboundMessageStatusType::FAILED) {
            $patch['error'] = trim(implode(': ', array_filter([$remote->errorCode, $remote->errorMessage])))
                ?: __d('rock_software_messaging_client', 'The gateway could not send it.');
        }
        if ($status === OutboundMessageStatusType::DELIVERED && $message->delivered_at === null) {
            $deliveredAt = $remote->data['timestamps']['delivered_at'] ?? null;
            $patch['delivered_at'] = is_string($deliveredAt) ? new DateTime($deliveredAt) : $now;
        }
        if ($isFinal) {
            $patch['final_status_at'] = $now;
        }
        $message->patch($patch, ['guard' => false]);
        $table->saveOrFail($message);
        if ($status === $previous) {
            return false;
        }

        $message->outbound_message_status_type = $table->OutboundMessageStatusTypes->forValue($status);
        EventManager::instance()->dispatch(new Event(self::EVENT_STATUS_CHANGED, $this, [
            'outboundMessage' => $message,
            'previousStatus' => $previous,
            'status' => $status,
        ]));

        return true;
    }

    /**
     * When to ask next, or null once the message is too old to ask about: half
     * the message's age from now, between `MIN_CHECK_DELAY` and
     * `MAX_CHECK_DELAY`, so questions thin out as it ages.
     *
     * @param \RockSoftware\MessagingClient\Model\Entity\OutboundMessage $message The message.
     * @param \Cake\I18n\DateTime $now Now.
     * @return \Cake\I18n\DateTime|null
     */
    private function nextCheck(OutboundMessage $message, DateTime $now): ?DateTime
    {
        if ($message->created->addHours(self::MAX_AGE_HOURS)->lessThanOrEquals($now)) {
            return null;
        }
        $age = max(0, $now->getTimestamp() - $message->created->getTimestamp());

        return $now->addSeconds(max(self::MIN_CHECK_DELAY, min(self::MAX_CHECK_DELAY, intdiv($age, 2))));
    }

    /**
     * @return \RockSoftware\MessagingClient\Model\Table\OutboundMessagesTable
     */
    private function outboundMessages(): OutboundMessagesTable
    {
        /** @var \RockSoftware\MessagingClient\Model\Table\OutboundMessagesTable */
        return $this->fetchTable('RockSoftware/MessagingClient.OutboundMessages');
    }
}
