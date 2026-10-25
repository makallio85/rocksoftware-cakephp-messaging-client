<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Service;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use DateTimeInterface;
use RockSoftware\MessagingClient\Credentials\SettingsCredentialsProvider;
use RockSoftware\MessagingClient\MessagingClientPlugin;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessageStatusType;
use RockSoftware\MessagingClient\Model\Table\OutboundMessagesTable;
use Throwable;

/**
 * Whether messaging works in this application, for a monitor: by codes
 * only, never a recipient, a subject or an address. Mirrors the gateway's
 * own health answer (`{status, problems, time}`).
 */
class Health
{
    use LocatorAwareTrait;

    public const STATUS_OK = 'ok';
    public const STATUS_DEGRADED = 'degraded';

    /** The gateway's address or token is missing (or cannot be read). */
    public const PROBLEM_GATEWAY_NOT_CONFIGURED = 'gateway_not_configured';

    /** No channel's sending switch is on at this address. */
    public const PROBLEM_NO_CHANNEL_ENABLED = 'no_channel_enabled';

    /** A message has waited for the gateway to accept it for an hour or more. */
    public const PROBLEM_MESSAGES_STUCK_PENDING = 'messages_stuck_pending';

    /** `healthFailureThreshold` or more messages failed in the last hour. */
    public const PROBLEM_RECENT_FAILURES = 'recent_failures';

    /** How old a pending message may be before it counts as stuck, in minutes. */
    public const STUCK_PENDING_MINUTES = 60;

    /**
     * @return array{status: string, problems: list<string>, time: string}
     */
    public function check(): array
    {
        $problems = [];
        try {
            if ((new SettingsCredentialsProvider())->credentials() === null) {
                $problems[] = self::PROBLEM_GATEWAY_NOT_CONFIGURED;
            }
        } catch (Throwable) {
            $problems[] = self::PROBLEM_GATEWAY_NOT_CONFIGURED;
        }
        if (!DeliveryGate::isAnyEnabled()) {
            $problems[] = self::PROBLEM_NO_CHANNEL_ENABLED;
        }

        $table = $this->outboundMessages();
        $now = DateTime::now();
        $statusId = fn(string $status): int => $table->OutboundMessageStatusTypes->idFor($status);
        $stuck = $table->find()
            ->where([
                'outbound_message_status_type_id' => $statusId(OutboundMessageStatusType::PENDING),
                'created <=' => $now->subMinutes(self::STUCK_PENDING_MINUTES),
            ])
            ->count();
        if ($stuck > 0) {
            $problems[] = self::PROBLEM_MESSAGES_STUCK_PENDING;
        }
        $failed = $table->find()
            ->where([
                'outbound_message_status_type_id' => $statusId(OutboundMessageStatusType::FAILED),
                'modified >=' => $now->subHours(1),
            ])
            ->count();
        if ($failed >= max(1, (int)MessagingClientPlugin::setting('healthFailureThreshold'))) {
            $problems[] = self::PROBLEM_RECENT_FAILURES;
        }

        return [
            'status' => $problems === [] ? self::STATUS_OK : self::STATUS_DEGRADED,
            'problems' => $problems,
            'time' => $now->setTimezone('UTC')->format(DateTimeInterface::ATOM),
        ];
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
