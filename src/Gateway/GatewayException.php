<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Gateway;

use RuntimeException;
use Throwable;

/**
 * The gateway failed or refused. The message is safe to show a person: it
 * never holds the token.
 *
 * - `httpStatus` is the gateway's HTTP status, 0 when no answer arrived.
 * - `isOutcomeUnknown` is true when the request may have reached the gateway
 *   and done its work (no answer, a 5xx, an answer that cannot be read).
 *   Sending the same `external_ref` again is the safe way to find out.
 * - `isTemporary` is true when trying again later may succeed: no answer, a
 *   redirect or a refused token (the address or the token is being fixed),
 *   `429` (quota or burst limit) and `5xx`. `403`, `404` and `422` are final:
 *   the same request will be refused again.
 * - `remoteMessage` is the gateway's own explanation, when it gave one.
 */
class GatewayException extends RuntimeException
{
    /**
     * @param string $message Safe to show.
     * @param int $httpStatus The HTTP status, 0 when no response arrived.
     * @param bool $isOutcomeUnknown True when the gateway may have acted.
     * @param bool $isTemporary True when a later attempt may succeed.
     * @param string $remoteMessage The gateway's own error message.
     * @param \Throwable|null $previous The cause.
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly bool $isOutcomeUnknown = false,
        public readonly bool $isTemporary = true,
        public readonly string $remoteMessage = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
