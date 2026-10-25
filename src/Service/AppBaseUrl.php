<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Service;

use Cake\Core\Configure;

/**
 * The application's own address, `App.fullBaseUrl`, in one normalised form:
 * what a sending switch is bound to.
 *
 * Only the configured value counts, never one derived from a request's `Host`
 * header: a header is the client's to choose, and a copy of the database on
 * another host must not be able to claim production's address.
 */
final class AppBaseUrl
{
    /**
     * The normalised `App.fullBaseUrl`, or null when it is not configured.
     *
     * @return string|null
     */
    public static function current(): ?string
    {
        $url = Configure::read('App.fullBaseUrl');

        return is_string($url) ? self::normalise($url) : null;
    }

    /**
     * Scheme and host lower-cased, the default port and trailing slashes dropped.
     *
     * @param string $url An address.
     * @return string|null Null when it is not an http(s) address.
     */
    public static function normalise(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $port = $parts['port'] ?? null;
        $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $path = rtrim($parts['path'] ?? '', '/');

        return $scheme . '://' . strtolower($parts['host'])
            . ($port !== null && !$isDefaultPort ? ':' . $port : '')
            . $path;
    }
}
