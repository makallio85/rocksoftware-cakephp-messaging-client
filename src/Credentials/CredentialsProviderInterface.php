<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Credentials;

/**
 * Where the gateway address, token and senders come from. In every
 * application it is `SettingsCredentialsProvider`, the plugin's settings page:
 * the plugin owns the messaging configuration. `Messenger` and
 * `MessagingSettings` take another one only so tests can pass
 * `StaticCredentialsProvider`.
 */
interface CredentialsProviderInterface
{
    /**
     * The credentials, or null when the gateway is not set up yet.
     *
     * @return \RockSoftware\MessagingClient\Credentials\Credentials|null
     */
    public function credentials(): ?Credentials;
}
