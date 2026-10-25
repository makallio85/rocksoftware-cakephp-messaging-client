<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Service;

use Cake\Utility\Security;
use RuntimeException;
use SensitiveParameter;

/**
 * Encrypts the stored API token with a key derived from the application's
 * security salt, so a database dump alone does not reveal it.
 */
final class SecretCipher
{
    private const CONTEXT = 'rocksoftware-cakephp-messaging-client:api-token';

    /**
     * @param string $plain The secret.
     * @return string Base64 ciphertext.
     */
    public static function encrypt(#[SensitiveParameter] string $plain): string
    {
        return base64_encode(Security::encrypt($plain, self::key()));
    }

    /**
     * @param string $stored Base64 ciphertext.
     * @return string The secret.
     * @throws \RuntimeException When it cannot be decrypted.
     */
    public static function decrypt(string $stored): string
    {
        $raw = base64_decode($stored, true);
        $plain = $raw === false ? null : Security::decrypt($raw, self::key());
        if ($plain === null) {
            throw new RuntimeException(__d(
                'rock_software_messaging_client',
                'The stored API token cannot be decrypted. Was the security salt changed? Enter the token again.',
            ));
        }

        return $plain;
    }

    /**
     * @return string
     */
    private static function key(): string
    {
        return hash('sha256', self::CONTEXT . Security::getSalt());
    }
}
