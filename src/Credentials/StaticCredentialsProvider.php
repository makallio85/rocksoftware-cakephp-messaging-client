<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Credentials;

/**
 * Credentials given in code, for tests.
 */
final class StaticCredentialsProvider implements CredentialsProviderInterface
{
    /**
     * @param \RockSoftware\MessagingClient\Credentials\Credentials|null $credentials The credentials; null for none.
     */
    public function __construct(private ?Credentials $credentials)
    {
    }

    /**
     * @inheritDoc
     */
    public function credentials(): ?Credentials
    {
        return $this->credentials;
    }
}
