<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient;

use InvalidArgumentException;

/**
 * A phone number in E.164 (`+358401234567`), from however a person wrote it.
 *
 * `040 123 4567`, `040-1234567`, `(040) 123 4567`, `+358 40 123 4567`,
 * `+358 (0)40 123 4567` and `00358 40 123 4567` are the same Finnish number.
 * A number without a country code gets the default one
 * (`RockSoftwareMessagingClient.defaultCountryCode`, `+358`), its national
 * trunk `0` dropped: `+358040…` would be a different, invalid number.
 *
 * Anything that cannot be a phone number is refused rather than guessed at:
 * texting a number made up from a typo is worse than not texting.
 */
final class PhoneNumber
{
    /** The country code a number without one gets, unless the application sets another. */
    public const DEFAULT_COUNTRY_CODE = '+358';

    /**
     * @param string $e164 The number, `+` and 8 to 15 digits.
     */
    private function __construct(public readonly string $e164)
    {
    }

    /**
     * @param string $raw The number as written.
     * @param string|null $defaultCountryCode Such as `+358` or `0046`; null for the application's setting.
     * @return self
     * @throws \InvalidArgumentException When it cannot be a phone number.
     */
    public static function parse(string $raw, ?string $defaultCountryCode = null): self
    {
        $value = trim($raw);
        // "+358 (0)40…": the bracketed trunk 0 written after a country code.
        $value = (string)preg_replace('/^(\+\d{1,3})\s*\(0\)/', '$1', $value);
        $value = (string)preg_replace('/[\s\-().\/]/u', '', $value);
        if ($value === '') {
            throw new InvalidArgumentException('A phone number is required.');
        }
        if (str_starts_with($value, '00')) {
            $value = '+' . substr($value, 2);
        }

        if (str_starts_with($value, '+')) {
            $digits = substr($value, 1);
            if (!ctype_digit($digits) || strlen($digits) < 8 || strlen($digits) > 15) {
                throw new InvalidArgumentException(sprintf('"%s" is not a phone number.', $raw));
            }

            return new self('+' . $digits);
        }
        if (!ctype_digit($value)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a phone number.', $raw));
        }

        $countryCode = self::countryCode($defaultCountryCode ?? self::configuredCountryCode());
        $national = ltrim($value, '0');
        if (strlen($national) < 6 || strlen($countryCode . $national) > 16) {
            throw new InvalidArgumentException(sprintf('"%s" is not a phone number.', $raw));
        }

        return new self($countryCode . $national);
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->e164;
    }

    /**
     * A country code as `+358`.
     *
     * @param string $code Such as `+358`, `358` or `00358`.
     * @return string
     * @throws \InvalidArgumentException
     */
    private static function countryCode(string $code): string
    {
        $digits = ltrim((string)preg_replace('/\D/', '', $code), '0');
        if ($digits === '' || strlen($digits) > 3) {
            throw new InvalidArgumentException(sprintf('"%s" is not a country calling code.', $code));
        }

        return '+' . $digits;
    }

    /**
     * @return string
     */
    private static function configuredCountryCode(): string
    {
        $code = MessagingClientPlugin::setting('defaultCountryCode');

        return is_string($code) && $code !== '' ? $code : self::DEFAULT_COUNTRY_CODE;
    }
}
