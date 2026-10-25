<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Credentials;

use SensitiveParameter;

/**
 * How the application reaches the gateway: its address and the API token.
 * The token is never printed. Who a message is sent as is a sender
 * (`SendersTable`).
 */
final class Credentials
{
    private string $token;

    /**
     * @param string $gatewayUrl The gateway's address, such as `https://messaging.example.com`.
     * @param string $token The API token issued in the gateway console.
     */
    public function __construct(
        public readonly string $gatewayUrl,
        #[SensitiveParameter]
        string $token,
    ) {
        $this->token = $token;
    }

    /**
     * @return string
     */
    public function token(): string
    {
        return $this->token;
    }

    /**
     * Never print the token, even in a debug dump.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['gatewayUrl' => $this->gatewayUrl];
    }
}
