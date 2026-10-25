<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Service;

use RockSoftware\MessagingClient\Gateway\GatewayClient;
use RockSoftware\MessagingClient\Gateway\GatewayConnection;
use RockSoftware\MessagingClient\Gateway\GatewayStatus;

/**
 * What "Test connection" found: whether the token works and may send, and
 * the three switches that decide whether a message reaches a person — this
 * application's delivery gate, the gateway's own switch, and whether the
 * token is a test token. Kept as plain values so it survives the redirect
 * back to the settings page.
 */
final class ConnectionCheck
{
    /**
     * The permissions a sending application's token needs.
     *
     * @var list<string>
     */
    public const REQUIRED_PERMISSIONS = [GatewayClient::PERMISSION_STATUS_READ, GatewayClient::PERMISSION_MESSAGE_SEND];

    /**
     * @param array<string, bool> $sendingEnabledChannels This application's sending switches, by
     *   `ChannelType::*`: whether each channel sends now (`DeliveryGate`).
     * @param bool $isConfigured Whether a gateway address and token are set.
     * @param string|null $error Why the gateway could not be checked, safe to show.
     * @param \RockSoftware\MessagingClient\Gateway\GatewayStatus|null $status The gateway's answer.
     * @param list<\RockSoftware\MessagingClient\Gateway\GatewayConnection> $connections What the token may send as.
     */
    public function __construct(
        public readonly array $sendingEnabledChannels,
        public readonly bool $isConfigured,
        public readonly ?string $error = null,
        public readonly ?GatewayStatus $status = null,
        public readonly array $connections = [],
    ) {
    }

    /**
     * Whether the gateway answered and confirmed the token.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->status !== null;
    }

    /**
     * The required permissions the token lacks.
     *
     * @return list<string>
     */
    public function missingPermissions(): array
    {
        if ($this->status === null) {
            return [];
        }

        return array_values(array_diff(self::REQUIRED_PERMISSIONS, $this->status->permissions));
    }

    /**
     * Whether a message sent now would reach a person on some channel: the
     * token works and may send, it is not a test token, the gateway's switch
     * is on and so is this application's for at least one channel.
     *
     * @return bool
     */
    public function isReachingPeople(): bool
    {
        return in_array(true, $this->sendingEnabledChannels, true)
            && $this->status !== null
            && $this->status->isSendingEnabled
            && !$this->status->isTestToken
            && $this->missingPermissions() === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sendingEnabledChannels' => $this->sendingEnabledChannels,
            'isConfigured' => $this->isConfigured,
            'error' => $this->error,
            'status' => $this->status === null ? null : [
                'tokenName' => $this->status->tokenName,
                'isTestToken' => $this->status->isTestToken,
                'permissions' => $this->status->permissions,
                'isSendingEnabled' => $this->status->isSendingEnabled,
                'webhookEvents' => $this->status->webhookEvents,
            ],
            'connections' => array_map(fn(GatewayConnection $connection): array => [
                'code' => $connection->code,
                'channel' => $connection->channel,
                'name' => $connection->name,
                'identity' => $connection->identity,
                'displayName' => $connection->displayName,
                'isTest' => $connection->isTest,
                'isOutboundEnabled' => $connection->isOutboundEnabled,
                'isOwned' => $connection->isOwned,
                'isInboundEnabled' => $connection->isInboundEnabled,
            ], $this->connections),
        ];
    }

    /**
     * @param array<string, mixed> $data A `toArray()`.
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $status = is_array($data['status'] ?? null) ? $data['status'] : null;
        $connections = [];
        foreach (is_array($data['connections'] ?? null) ? $data['connections'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $connections[] = new GatewayConnection(
                (string)($row['code'] ?? ''),
                (string)($row['channel'] ?? ''),
                (string)($row['name'] ?? ''),
                (string)($row['identity'] ?? ''),
                (string)($row['displayName'] ?? ''),
                (bool)($row['isTest'] ?? false),
                (bool)($row['isOutboundEnabled'] ?? false),
                (bool)($row['isOwned'] ?? false),
                (bool)($row['isInboundEnabled'] ?? false),
            );
        }

        return new self(
            array_map('boolval', (array)($data['sendingEnabledChannels'] ?? [])),
            (bool)($data['isConfigured'] ?? false),
            is_string($data['error'] ?? null) ? $data['error'] : null,
            $status === null ? null : new GatewayStatus(
                (string)($status['tokenName'] ?? ''),
                (bool)($status['isTestToken'] ?? true),
                array_values(array_map('strval', (array)($status['permissions'] ?? []))),
                (bool)($status['isSendingEnabled'] ?? false),
                is_array($status['webhookEvents'] ?? null) ? array_values($status['webhookEvents']) : null,
            ),
            $connections,
        );
    }
}
