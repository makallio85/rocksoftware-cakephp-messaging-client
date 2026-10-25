<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Model\Entity;

use Cake\ORM\Entity;

/**
 * The application's connection to the gateway, kept by the settings page.
 *
 * @property int $id
 * @property string|null $gateway_url
 * @property string|null $api_token Encrypted by `SecretCipher`; never shown.
 * @property string|null $email_account Only for handing a sender over from an application's own data
 *   migration: `SendersTable::adoptLegacyAccounts()` makes it the email default and empties it.
 * @property string|null $sms_account The same for SMS.
 * @property \Cake\I18n\DateTime|null $checked_at When "Test connection" last ran.
 * @property bool|null $is_connection_ok Whether the gateway then answered and confirmed the token.
 * @property string|null $token_name The token's name in the gateway console.
 * @property bool|null $is_test_token Whether the token is a test token (everything sent with it is trapped).
 * @property bool|null $is_gateway_sending_enabled The gateway's own sending switch then.
 * @property string|null $check_error Why the check failed.
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class Setting extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'gateway_url' => true,
    ];

    /**
     * @var list<string>
     */
    protected array $_hidden = ['api_token'];

    /**
     * Whether a token is stored.
     *
     * @return bool
     */
    public function hasToken(): bool
    {
        return ($this->api_token ?? '') !== '';
    }

    /**
     * Never print the token, even encrypted, in a debug dump.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $info = parent::__debugInfo();
        unset($info['api_token']);
        $info['hasToken'] = $this->hasToken();

        return $info;
    }
}
