<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Service;

use Cake\I18n\DateTime;

/**
 * One channel's sending switch, as the settings page shows it.
 */
final class ChannelSwitch
{
    /**
     * @param string $channel `ChannelType::*`.
     * @param bool $isSwitchedOn Whether its stored switch is on.
     * @param string|null $enabledForUrl The address it was switched on at.
     * @param string|null $currentUrl This application's address (`App.fullBaseUrl`), normalised; null when unset.
     * @param string|null $changedBy Who switched it last.
     * @param \Cake\I18n\DateTime|null $changedAt When.
     * @param bool $isForcedOff Whether the application's `isDeliveryEnabled` forces it off.
     */
    public function __construct(
        public readonly string $channel,
        public readonly bool $isSwitchedOn,
        public readonly ?string $enabledForUrl,
        public readonly ?string $currentUrl,
        public readonly ?string $changedBy,
        public readonly ?DateTime $changedAt,
        public readonly bool $isForcedOff = false,
    ) {
    }

    /**
     * Whether it was switched on at this very address.
     *
     * @return bool
     */
    public function isBoundHere(): bool
    {
        $stored = $this->enabledForUrl === null ? null : AppBaseUrl::normalise($this->enabledForUrl);

        return $stored !== null && $this->currentUrl !== null && $stored === $this->currentUrl;
    }

    /**
     * Whether the channel sends now.
     *
     * @return bool
     */
    public function isOn(): bool
    {
        return $this->isSwitchedOn && $this->isBoundHere() && !$this->isForcedOff;
    }

    /**
     * Switched on, but at another address: a copy of another installation's database.
     *
     * @return bool
     */
    public function isSwitchedOnElsewhere(): bool
    {
        return $this->isSwitchedOn && !$this->isBoundHere();
    }
}
