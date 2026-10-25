<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient;

use Cake\Database\Exception\QueryException;
use Cake\Http\Client;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use InvalidArgumentException;
use PDOException;
use RockSoftware\MessagingClient\Credentials\CredentialsProviderInterface;
use RockSoftware\MessagingClient\Credentials\SettingsCredentialsProvider;
use RockSoftware\MessagingClient\Gateway\GatewayClient;
use RockSoftware\MessagingClient\Gateway\GatewayException;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessage;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessageStatusType;
use RockSoftware\MessagingClient\Model\Table\OutboundMessagesTable;
use RockSoftware\MessagingClient\Service\DeliveryGate;
use RockSoftware\MessagingClient\Service\StatusRefresher;
use RuntimeException;
use Throwable;

/**
 * The one way an application sends a message: record it, then deliver it.
 *
 * ```php
 * $message = (new Messenger())->send(SendRequest::email(
 *     to: $approver->email,
 *     externalRef: 'change-request-' . $request->id . '-approver-' . $approver->id,
 *     subject: __('A change request waits for you'),
 *     text: $body,
 * ));
 * $message->isAccepted(); // queued at the gateway
 * ```
 *
 * - **Send once.** The same `external_ref` again returns the row already
 *   recorded and sends nothing. The gateway enforces the same, so a retry
 *   after an unknown outcome is safe.
 * - **The delivery gate.** With its channel's sending switch off, or switched
 *   on at another address (`DeliveryGate`), the message is recorded as
 *   `NOT_SENT_DELIVERY_OFF` and the gateway is never called.
 * - **Retries.** No answer, a refused token, `429` or a `5xx` leave the
 *   message `PENDING`; `bin/cake messaging_client deliver` tries it again
 *   after `retryDelays`, and it ends `FAILED` when they run out. A refusal
 *   (`403`, `404`, `422`) ends it `FAILED` at once with the gateway's reason.
 * - **The sender.** A request names a sender (`sender:`), which must be an
 *   enabled sender of its channel on the settings page, or uses the channel's
 *   default sender, or, with none, the gateway's own default.
 * - **Never throws for a delivery problem.** The outcome is on the returned
 *   row. Only a request that is not a valid message throws, before anything is
 *   stored (`SendRequest`'s constructor, or a sender it may not use).
 */
class Messenger
{
    use LocatorAwareTrait;

    private ?CredentialsProviderInterface $credentialsProvider;

    private ?Client $http;

    /**
     * @param \RockSoftware\MessagingClient\Credentials\CredentialsProviderInterface|null $credentialsProvider
     *   Where the gateway address and token come from; null for the settings page's (tests pass their own).
     * @param \Cake\Http\Client|null $http The HTTP client; null for `httpClient` in the settings, else a new one.
     */
    public function __construct(?CredentialsProviderInterface $credentialsProvider = null, ?Client $http = null)
    {
        $this->credentialsProvider = $credentialsProvider;
        $this->http = $http;
    }

    /**
     * Records the message and makes its first attempt.
     *
     * @param \RockSoftware\MessagingClient\SendRequest $request The message.
     * @param int|null $resentFromId The message this one resends (`resend()` passes it).
     * @return \RockSoftware\MessagingClient\Model\Entity\OutboundMessage The row, with its status loaded.
     */
    public function send(SendRequest $request, ?int $resentFromId = null): OutboundMessage
    {
        $table = $this->outboundMessages();
        $existing = $table->findByExternalRef($request->externalRef);
        if ($existing !== null) {
            return $existing;
        }

        $request = $this->withSender($request);
        $now = DateTime::now();
        $request = $request->withDeadlineFrom($now);
        $message = $this->record($request, $now, $resentFromId);
        if ($message === null) {
            // Another process recorded the same external_ref a moment earlier.
            return $table->findByExternalRef($request->externalRef) ?? throw new RuntimeException(
                sprintf('Outbound message %s could not be recorded.', $request->externalRef),
            );
        }

        return $this->attempt($message, $request, $now);
    }

    /**
     * The request with the sender it goes out as: the one it names, which
     * must be an enabled sender on its channel, else the channel's default
     * sender, else none (the gateway's own default for the application).
     *
     * @param \RockSoftware\MessagingClient\SendRequest $request The message.
     * @return \RockSoftware\MessagingClient\SendRequest
     * @throws \InvalidArgumentException When it names a sender it may not use.
     */
    private function withSender(SendRequest $request): SendRequest
    {
        /** @var \RockSoftware\MessagingClient\Model\Table\SendersTable $senders */
        $senders = $this->fetchTable('RockSoftware/MessagingClient.Senders');
        $senders->adoptLegacyAccounts();
        if ($request->sender === null) {
            return $request->withDefaultSender($senders->defaultFor($request->channel)?->code);
        }
        $sender = $senders->findByCode($request->sender);
        if ($sender === null || $sender->channel_type?->value !== $request->channel || !$sender->isUsable()) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not an enabled %s sender of this application.',
                $request->sender,
                $request->channel,
            ));
        }

        return $request;
    }

    /**
     * Sends a message again as a new outbound message under `$newExternalRef`:
     * the same channel, recipients, sender and content, with no deadline. The
     * old row is never changed; the new one points to it (`resent_from_id`).
     * Only a message that is no longer pending can be resent.
     *
     * @param \RockSoftware\MessagingClient\Model\Entity\OutboundMessage $message The message to send again.
     * @param string $newExternalRef The new send's own id.
     * @return \RockSoftware\MessagingClient\Model\Entity\OutboundMessage The new message.
     * @throws \InvalidArgumentException When it is still pending, its request is not kept, the ref is taken,
     *   or its sender may no longer be used.
     */
    public function resend(OutboundMessage $message, string $newExternalRef): OutboundMessage
    {
        $table = $this->outboundMessages();
        $stored = $table->find('withTypes')->where([$table->aliasField('id') => $message->id])->first();
        if (!$stored instanceof OutboundMessage) {
            throw new InvalidArgumentException('Only a recorded outbound message can be resent.');
        }
        if ($stored->isPending()) {
            throw new InvalidArgumentException(sprintf(
                'Outbound message %s is still being sent; it cannot be resent yet.',
                $stored->external_ref,
            ));
        }
        if (!is_array($stored->payload) || $stored->payload === []) {
            throw new InvalidArgumentException(sprintf(
                'Outbound message %s was recorded before its content was kept; it cannot be resent.',
                $stored->external_ref,
            ));
        }
        if ($table->findByExternalRef($newExternalRef) !== null) {
            throw new InvalidArgumentException(sprintf('external_ref %s is already used.', $newExternalRef));
        }
        $payload = $stored->payload;
        unset($payload['expires_at'], $payload['valid_for']);
        $payload['external_ref'] = $newExternalRef;

        return $this->send(SendRequest::fromPayload($payload), $stored->id);
    }

    /**
     * Tries every pending message whose next attempt is due, up to `$limit`.
     *
     * @param int|null $limit How many; null for `deliverBatchSize` in the settings.
     * @return array<string, int> How many ended in each status, by `OutboundMessageStatusType::*`.
     */
    public function deliverDue(?int $limit = null): array
    {
        $limit ??= (int)MessagingClientPlugin::setting('deliverBatchSize');
        $now = DateTime::now();
        $table = $this->outboundMessages();
        $counts = array_fill_keys(
            [OutboundMessageStatusType::PENDING, ...OutboundMessageStatusType::FINAL],
            0,
        );

        /** @var list<\RockSoftware\MessagingClient\Model\Entity\OutboundMessage> $due */
        $due = $table->find('due', now: $now)->limit($limit)->all()->toList();
        foreach ($due as $message) {
            if (!$table->claim($message, $now->addSeconds($this->delayBefore($message->attempts + 1)))) {
                continue;
            }
            $message = $this->deliver($message, $now);
            $counts[(string)$message->statusValue()]++;
        }

        return $counts;
    }

    /**
     * One attempt for a pending message read from the log.
     *
     * @param \RockSoftware\MessagingClient\Model\Entity\OutboundMessage $message A pending message.
     * @param \Cake\I18n\DateTime|null $now Now.
     * @return \RockSoftware\MessagingClient\Model\Entity\OutboundMessage
     */
    public function deliver(OutboundMessage $message, ?DateTime $now = null): OutboundMessage
    {
        $now ??= DateTime::now();
        if ($message->outbound_message_status_type === null) {
            $message->outbound_message_status_type = $this->outboundMessages()->OutboundMessageStatusTypes
                ->get($message->outbound_message_status_type_id);
        }
        if (!$message->isPending()) {
            return $message;
        }
        try {
            $request = SendRequest::fromPayload((array)$message->payload);
        } catch (InvalidArgumentException $exception) {
            return $this->settle($message, OutboundMessageStatusType::FAILED, $exception->getMessage());
        }

        return $this->attempt($message, $request, $now);
    }

    /**
     * @param \RockSoftware\MessagingClient\SendRequest $request The message, its deadline fixed.
     * @param \Cake\I18n\DateTime $now Now.
     * @param int|null $resentFromId The message this one resends.
     * @return \RockSoftware\MessagingClient\Model\Entity\OutboundMessage|null Null when the same
     *   `external_ref` was recorded first by another process.
     */
    private function record(SendRequest $request, DateTime $now, ?int $resentFromId = null): ?OutboundMessage
    {
        $table = $this->outboundMessages();
        /** @var \RockSoftware\MessagingClient\Model\Entity\OutboundMessage $message */
        $message = $table->newEmptyEntity();
        $message->patch([
            'external_ref' => $request->externalRef,
            'channel_type_id' => $table->ChannelTypes->idFor($request->channel),
            'outbound_message_status_type_id' => $table->OutboundMessageStatusTypes
                ->idFor(OutboundMessageStatusType::PENDING),
            'sender_code' => $request->sender,
            'resent_from_id' => $resentFromId,
            'recipient' => mb_substr(implode(', ', $request->to), 0, 255),
            'subject' => $request->subject === null ? null : mb_substr($request->subject, 0, 255),
            'attempts' => 0,
            'context' => $request->context === [] ? null : $request->context,
            'payload' => $request->toPayload(),
            'expires_at' => $request->expiresAt === null ? null : DateTime::createFromInterface($request->expiresAt),
            // Claimed by this call: should it die mid-attempt, the worker takes over then.
            'next_attempt_at' => $now->addSeconds($this->delayBefore(1)),
        ], ['guard' => false]);

        try {
            $table->saveOrFail($message);
        } catch (QueryException | PDOException $exception) {
            if ($table->findByExternalRef($request->externalRef) === null) {
                throw $exception;
            }

            return null;
        }
        $message->channel_type = $table->ChannelTypes->forValue($request->channel);
        $message->outbound_message_status_type = $table->OutboundMessageStatusTypes
            ->forValue(OutboundMessageStatusType::PENDING);
        $message->clean();

        return $message;
    }

    /**
     * @param \RockSoftware\MessagingClient\Model\Entity\OutboundMessage $message A pending message.
     * @param \RockSoftware\MessagingClient\SendRequest $request Its request.
     * @param \Cake\I18n\DateTime $now Now.
     * @return \RockSoftware\MessagingClient\Model\Entity\OutboundMessage
     */
    private function attempt(OutboundMessage $message, SendRequest $request, DateTime $now): OutboundMessage
    {
        if (!DeliveryGate::isEnabledFor($request->channel)) {
            Log::info(sprintf(
                "Not sent, sending is off for %s in this application: to %s, %s\n%s",
                $request->channel,
                $message->recipient,
                $request->subject ?? $request->externalRef,
                $request->text ?? $request->html ?? '',
            ));

            return $this->settle($message, OutboundMessageStatusType::NOT_SENT_DELIVERY_OFF, __d(
                'rock_software_messaging_client',
                'Sending {0} is switched off in this application.',
                ChannelType::label($request->channel),
            ));
        }
        if ($message->expires_at !== null && $message->expires_at->lessThanOrEquals($now)) {
            return $this->settle($message, OutboundMessageStatusType::FAILED, __d(
                'rock_software_messaging_client',
                'It expired before the gateway accepted it.',
            ));
        }

        $message->attempts = $message->attempts + 1;
        $message->last_attempted_at = $now;

        try {
            $credentials = ($this->credentialsProvider ?? new SettingsCredentialsProvider())->credentials();
            if ($credentials === null) {
                return $this->retryLater($message, $now, __d(
                    'rock_software_messaging_client',
                    'The messaging gateway is not set up yet.',
                ));
            }
            $client = GatewayClient::fromCredentials($credentials, $this->http);
        } catch (Throwable $exception) {
            return $this->retryLater($message, $now, $exception->getMessage());
        }

        try {
            $result = $client->send($request);
        } catch (GatewayException $exception) {
            if ($exception->isTemporary) {
                return $this->retryLater($message, $now, $exception->getMessage());
            }
            Log::error(sprintf(
                'The messaging gateway refused outbound message %s: %s',
                $message->external_ref,
                $exception->getMessage(),
            ));

            return $this->settle($message, OutboundMessageStatusType::FAILED, $exception->getMessage());
        }

        $message->gateway_message_uuid = $result->message->uuid;
        $message->is_test = $result->message->isTest;
        if ($result->isSuppressed()) {
            return $this->settle($message, OutboundMessageStatusType::SUPPRESSED, __d(
                'rock_software_messaging_client',
                'The recipient is on the gateway\'s block list.',
            ));
        }

        // The worker asks the gateway what became of it (`StatusRefresher`).
        $message->next_status_check_at = $now->addSeconds(StatusRefresher::FIRST_CHECK_DELAY);

        return $this->settle($message, OutboundMessageStatusType::ACCEPTED, null);
    }

    /**
     * Leaves the message pending for the next attempt, or fails it when the
     * retries have run out.
     *
     * @param \RockSoftware\MessagingClient\Model\Entity\OutboundMessage $message The message.
     * @param \Cake\I18n\DateTime $now Now.
     * @param string $error Why this attempt did not succeed.
     * @return \RockSoftware\MessagingClient\Model\Entity\OutboundMessage
     */
    private function retryLater(OutboundMessage $message, DateTime $now, string $error): OutboundMessage
    {
        $delays = $this->retryDelays();
        if ($message->attempts > count($delays)) {
            Log::error(sprintf(
                'Outbound message %s failed after %d attempts: %s',
                $message->external_ref,
                $message->attempts,
                $error,
            ));

            return $this->settle($message, OutboundMessageStatusType::FAILED, __d(
                'rock_software_messaging_client',
                'Gave up after {0} attempts. {1}',
                $message->attempts,
                $error,
            ));
        }

        $table = $this->outboundMessages();
        $message->patch([
            'outbound_message_status_type_id' => $table->OutboundMessageStatusTypes
                ->idFor(OutboundMessageStatusType::PENDING),
            'error' => $error,
            'next_attempt_at' => $now->addSeconds($this->delayBefore($message->attempts + 1)),
        ], ['guard' => false]);
        $table->saveOrFail($message);
        $message->outbound_message_status_type = $table->OutboundMessageStatusTypes
            ->forValue(OutboundMessageStatusType::PENDING);

        return $message;
    }

    /**
     * Ends the attempts in a final status. The request stays in `payload`, so
     * the message can be resent (`resend()`).
     *
     * @param \RockSoftware\MessagingClient\Model\Entity\OutboundMessage $message The message.
     * @param string $status `OutboundMessageStatusType::*`, a final one.
     * @param string|null $error Why it was not accepted.
     * @return \RockSoftware\MessagingClient\Model\Entity\OutboundMessage
     */
    private function settle(OutboundMessage $message, string $status, ?string $error): OutboundMessage
    {
        $table = $this->outboundMessages();
        $message->patch([
            'outbound_message_status_type_id' => $table->OutboundMessageStatusTypes->idFor($status),
            'error' => $error,
            'next_attempt_at' => null,
        ], ['guard' => false]);
        $table->saveOrFail($message);
        $message->outbound_message_status_type = $table->OutboundMessageStatusTypes->forValue($status);

        return $message;
    }

    /**
     * Seconds to wait before attempt number `$attempt` may start (the first
     * attempt's value is how long its claim lasts).
     *
     * @param int $attempt From 1.
     * @return int
     */
    private function delayBefore(int $attempt): int
    {
        $delays = $this->retryDelays();
        if ($delays === []) {
            return 60;
        }

        return $delays[max(0, min($attempt - 2, count($delays) - 1))];
    }

    /**
     * @return list<int>
     */
    private function retryDelays(): array
    {
        $delays = MessagingClientPlugin::setting('retryDelays');

        return is_array($delays) ? array_values(array_map('intval', $delays)) : [];
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
