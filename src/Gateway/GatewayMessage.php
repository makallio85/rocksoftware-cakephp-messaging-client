<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Gateway;

/**
 * A message as the gateway holds it: the fields this plugin reads from the
 * gateway's message object, and the whole object in `data`.
 */
final class GatewayMessage
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TRAPPED = 'trapped';
    public const STATUS_SUPPRESSED = 'suppressed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @param string|null $uuid The gateway's id of the message.
     * @param string|null $status `STATUS_*`.
     * @param string|null $externalRef The application's id for the send.
     * @param bool $isTest True when it is trapped and reaches no one.
     * @param string|null $errorCode The gateway's `error.code` (`bounced`, `expired`, `21610`…).
     * @param string|null $errorMessage The gateway's `error.message`.
     * @param array<string, mixed> $data The message object as the gateway answered.
     */
    public function __construct(
        public readonly ?string $uuid,
        public readonly ?string $status,
        public readonly ?string $externalRef,
        public readonly bool $isTest,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly array $data,
    ) {
    }

    /**
     * @param array<string, mixed> $data A message object.
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $code = $error['code'] ?? null;

        return new self(
            is_string($data['uuid'] ?? null) ? $data['uuid'] : null,
            is_string($data['status'] ?? null) ? $data['status'] : null,
            is_string($data['external_ref'] ?? null) ? $data['external_ref'] : null,
            ($data['is_test'] ?? false) === true,
            is_string($code) || is_int($code) ? (string)$code : null,
            is_string($error['message'] ?? null) ? $error['message'] : null,
            $data,
        );
    }
}
