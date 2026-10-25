<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Gateway;

/**
 * The gateway's `GET /system/status`: the token works, what it may do, and
 * whether anything sent with it reaches a person.
 */
final class GatewayStatus
{
    /**
     * @param string $tokenName The token's name in the gateway console.
     * @param bool $isTestToken True when everything sent with it is trapped and reaches no one.
     * @param list<string> $permissions The token's permissions (`message.send`…).
     * @param bool $isSendingEnabled The gateway's own sending switch.
     * @param list<string>|null $webhookEvents The events the application has an active webhook for.
     */
    public function __construct(
        public readonly string $tokenName,
        public readonly bool $isTestToken,
        public readonly array $permissions,
        public readonly bool $isSendingEnabled,
        public readonly ?array $webhookEvents,
    ) {
    }

    /**
     * @param string $permission A permission key.
     * @return bool
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
