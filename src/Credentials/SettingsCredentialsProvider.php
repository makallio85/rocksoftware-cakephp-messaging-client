<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Credentials;

use Cake\ORM\Locator\LocatorAwareTrait;
use RockSoftware\MessagingClient\Service\SecretCipher;

/**
 * The credentials kept by the settings page in
 * `rock_software_messaging_client_settings`, the token decrypted with the key
 * derived from the security salt.
 */
class SettingsCredentialsProvider implements CredentialsProviderInterface
{
    use LocatorAwareTrait;

    /**
     * @inheritDoc
     */
    public function credentials(): ?Credentials
    {
        /** @var \RockSoftware\MessagingClient\Model\Table\SettingsTable $table */
        $table = $this->fetchTable('RockSoftware/MessagingClient.Settings');
        $setting = $table->current();
        if ($setting === null || trim((string)$setting->gateway_url) === '' || !$setting->hasToken()) {
            return null;
        }

        return new Credentials((string)$setting->gateway_url, SecretCipher::decrypt((string)$setting->api_token));
    }
}
