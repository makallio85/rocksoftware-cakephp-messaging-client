<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Model\Entity;

use Cake\ORM\Entity;

/**
 * How a message travels. The rows are system rows; the plugin binds to them by
 * value, and the values are the gateway's own channel names.
 *
 * @property int $id
 * @property string $value
 * @property string $label
 * @property int $sort_order
 */
class ChannelType extends Entity
{
    public const EMAIL = 'email';
    public const SMS = 'sms';

    /**
     * @var list<string>
     */
    public const VALUES = [self::EMAIL, self::SMS];

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
            self::EMAIL => __d('rock_software_messaging_client', 'Email'),
            self::SMS => __d('rock_software_messaging_client', 'SMS'),
            default => $value,
        };
    }
}
