<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Service;

use ArrayAccess;

/**
 * Names whoever acted on the settings page, from the identity the
 * application's authentication left, without knowing the application's users
 * table: its `name`, else its `email`, else `#` and its id.
 */
final class Actor
{
    /**
     * @param mixed $identity The request's `identity` attribute.
     * @return string|null
     */
    public static function label(mixed $identity): ?string
    {
        foreach (['name', 'email'] as $field) {
            $value = self::field($identity, $field);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        $id = is_object($identity) && method_exists($identity, 'getIdentifier')
            ? $identity->getIdentifier()
            : self::field($identity, 'id');

        return is_scalar($id) && (string)$id !== '' ? '#' . $id : null;
    }

    /**
     * @param mixed $identity The identity.
     * @param string $field A field.
     * @return mixed
     */
    private static function field(mixed $identity, string $field): mixed
    {
        if (is_array($identity) || $identity instanceof ArrayAccess) {
            return $identity[$field] ?? null;
        }
        if (is_object($identity) && method_exists($identity, 'get')) {
            return $identity->get($field);
        }

        return null;
    }
}
