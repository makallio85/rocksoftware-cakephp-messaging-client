<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Model\Entity;

use Cake\ORM\Entity;

/**
 * Where an outbound message stands on this side of the gateway. The rows are
 * system rows; the plugin binds to them by value.
 *
 * - `PENDING`: recorded, not yet accepted by the gateway: the first attempt is
 *   under way, or it met a temporary error or an unknown outcome and
 *   `bin/cake messaging_client deliver` tries it again.
 * - `ACCEPTED`: the gateway queued it. What happens after that (sent,
 *   delivered, trapped by a test token) is the gateway's to report.
 * - `SUPPRESSED`: the recipient is on the gateway's block list; never sent.
 * - `FAILED`: refused for good, expired, given up after the last retry, or
 *   failed at the gateway afterwards (a bounce: `error` says which).
 * - `SENT`: the provider took it (the gateway's `sent`); an email ends here,
 *   since email never reports delivery.
 * - `DELIVERED`: the provider says it reached the recipient.
 * - `TRAPPED`: the gateway deliberately did not send it (a test token or a
 *   connection in test mode).
 * - `CANCELLED`: withdrawn at the gateway while still queued.
 * - `NOT_SENT_DELIVERY_OFF`: sending on its channel is switched off in this
 *   application (`DeliveryGate`); nothing was sent and nothing will be.
 *
 * @property int $id
 * @property string $value
 * @property string $label
 * @property int $sort_order
 */
class OutboundMessageStatusType extends Entity
{
    public const PENDING = 'PENDING';
    public const ACCEPTED = 'ACCEPTED';
    public const SUPPRESSED = 'SUPPRESSED';
    public const FAILED = 'FAILED';
    public const NOT_SENT_DELIVERY_OFF = 'NOT_SENT_DELIVERY_OFF';
    public const SENT = 'SENT';
    public const DELIVERED = 'DELIVERED';
    public const TRAPPED = 'TRAPPED';
    public const CANCELLED = 'CANCELLED';

    /**
     * The statuses an attempt to hand a message to the gateway ends in.
     *
     * @var list<string>
     */
    public const FINAL = [self::ACCEPTED, self::SUPPRESSED, self::FAILED, self::NOT_SENT_DELIVERY_OFF];

    /**
     * The statuses the gateway may still move on (`ACCEPTED`, `SENT`), which
     * the worker asks about.
     *
     * @var list<string>
     */
    public const AWAITING_GATEWAY = [self::ACCEPTED, self::SENT];

    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'value' => true,
        'label' => true,
        'sort_order' => true,
    ];

    /**
     * The label in the application's language.
     *
     * @param string $value One of the value constants.
     * @return string
     */
    public static function label(string $value): string
    {
        return match ($value) {
            self::PENDING => __d('rock_software_messaging_client', 'Waiting to be sent'),
            self::ACCEPTED => __d('rock_software_messaging_client', 'Accepted by the gateway'),
            self::SUPPRESSED => __d('rock_software_messaging_client', 'Not sent: recipient blocked'),
            self::FAILED => __d('rock_software_messaging_client', 'Failed'),
            self::NOT_SENT_DELIVERY_OFF => __d('rock_software_messaging_client', 'Not sent: delivery is off'),
            self::SENT => __d('rock_software_messaging_client', 'Sent by the provider'),
            self::DELIVERED => __d('rock_software_messaging_client', 'Delivered'),
            self::TRAPPED => __d('rock_software_messaging_client', 'Trapped: a test, reached no one'),
            self::CANCELLED => __d('rock_software_messaging_client', 'Cancelled'),
            default => $value,
        };
    }
}
