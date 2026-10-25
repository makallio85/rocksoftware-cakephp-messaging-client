<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\TestSuite\Fixture;

/**
 * Every fixture an application's test needs for the plugin's tables, for its
 * `$fixtures`: `[...MessagingClientFixtures::ALL, 'app.Users']`.
 */
final class MessagingClientFixtures
{
    /**
     * @var list<class-string<\Cake\TestSuite\Fixture\TestFixture>>
     */
    public const ALL = [
        ChannelTypesFixture::class,
        OutboundMessageStatusTypesFixture::class,
        SettingsFixture::class,
        ChannelSettingsFixture::class,
        SendersFixture::class,
        OutboundMessagesFixture::class,
    ];
}
