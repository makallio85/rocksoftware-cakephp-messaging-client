<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Service;

use Cake\ORM\Locator\LocatorAwareTrait;
use RockSoftware\MessagingClient\MessagingClientPlugin;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;
use RockSoftware\MessagingClient\Model\Table\ChannelSettingsTable;

/**
 * Whether this application may send on a channel now.
 *
 * A channel sends only when its switch on the settings page is on **and** was
 * switched on at this application's own address (`App.fullBaseUrl`). A
 * production database restored onto another host therefore reads as off on
 * every channel until someone there switches it on. No switch means off.
 *
 * `RockSoftwareMessagingClient.isDeliveryEnabled` (false, or a callable
 * receiving the channel and returning false) can force a channel off; it can
 * never force one on.
 *
 * The gateway has a switch of its own, and a test token traps everything; the
 * settings page shows all three.
 */
final class DeliveryGate
{
    use LocatorAwareTrait;

    /**
     * Whether a channel sends now.
     *
     * @param string $channel `ChannelType::*`.
     * @return bool
     */
    public static function isEnabledFor(string $channel): bool
    {
        if (self::isForcedOff($channel)) {
            return false;
        }

        return self::switchOf($channel)->isOn();
    }

    /**
     * The switch of every channel, by `ChannelType::*`.
     *
     * @return array<string, \RockSoftware\MessagingClient\Service\ChannelSwitch>
     */
    public static function switches(): array
    {
        $switches = [];
        foreach (ChannelType::VALUES as $channel) {
            $switches[$channel] = self::switchOf($channel);
        }

        return $switches;
    }

    /**
     * Whether any channel sends now.
     *
     * @return bool
     */
    public static function isAnyEnabled(): bool
    {
        foreach (ChannelType::VALUES as $channel) {
            if (self::isEnabledFor($channel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The switch of one channel as stored, read against this address.
     *
     * @param string $channel `ChannelType::*`.
     * @return \RockSoftware\MessagingClient\Service\ChannelSwitch
     */
    public static function switchOf(string $channel): ChannelSwitch
    {
        $setting = self::table()->forChannel($channel);
        $url = $setting?->enabled_for_url;

        return new ChannelSwitch(
            $channel,
            $setting?->is_sending_enabled === true,
            $url,
            AppBaseUrl::current(),
            $setting?->changed_by,
            $setting?->changed_at,
            self::isForcedOff($channel),
        );
    }

    /**
     * @param string $channel `ChannelType::*`.
     * @return bool
     */
    private static function isForcedOff(string $channel): bool
    {
        $setting = MessagingClientPlugin::setting('isDeliveryEnabled');
        if ($setting === false) {
            return true;
        }

        return is_callable($setting) && $setting($channel) === false;
    }

    /**
     * @return \RockSoftware\MessagingClient\Model\Table\ChannelSettingsTable
     */
    private static function table(): ChannelSettingsTable
    {
        /** @var \RockSoftware\MessagingClient\Model\Table\ChannelSettingsTable */
        return (new self())->fetchTable('RockSoftware/MessagingClient.ChannelSettings');
    }
}
