<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\TestSuite;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use RockSoftware\MessagingClient\MessagingClientPlugin;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;
use RockSoftware\MessagingClient\Service\AppBaseUrl;
use RockSoftware\MessagingClient\Service\SecretCipher;

/**
 * For an application's tests: every gateway call goes to a `FakeGateway`,
 * and assertions say what would have been sent. Use it in a CakePHP
 * `TestCase`, which restores `Configure` after each test.
 *
 * ```php
 * $this->useFakeGateway();
 * $this->post('/change-requests/17/submit');
 * $this->assertMessageSent(['to' => 'approver@example.test', 'channel' => 'email']);
 * ```
 */
trait MessagingTestTrait
{
    protected ?FakeGateway $fakeGateway = null;

    /**
     * Sends everything to a new `FakeGateway`, set up the way a person would
     * on the settings page: the gateway's address and a token stored, and the
     * channels' sending switched on at this application's address. Load
     * `Fixture\MessagingClientFixtures::ALL` in the test.
     *
     * @param bool $isDeliveryEnabled Whether the sending switches are on (so the fake sees the sends).
     * @param bool $isConfigured Whether the gateway's address and a token are stored.
     * @return \RockSoftware\MessagingClient\TestSuite\FakeGateway
     */
    protected function useFakeGateway(bool $isDeliveryEnabled = true, bool $isConfigured = true): FakeGateway
    {
        $this->fakeGateway = new FakeGateway();
        Configure::write(MessagingClientPlugin::CONFIG_KEY . '.httpClient', $this->fakeGateway);
        if (AppBaseUrl::current() === null) {
            Configure::write('App.fullBaseUrl', 'https://app.example.test');
        }
        $locator = TableRegistry::getTableLocator();

        /** @var \RockSoftware\MessagingClient\Model\Table\ChannelSettingsTable $switches */
        $switches = $locator->get('RockSoftware/MessagingClient.ChannelSettings');
        foreach (ChannelType::VALUES as $channel) {
            $switches->switchSending($channel, $isDeliveryEnabled, AppBaseUrl::current(), 'test');
        }

        /** @var \RockSoftware\MessagingClient\Model\Table\SettingsTable $settings */
        $settings = $locator->get('RockSoftware/MessagingClient.Settings');
        $setting = $settings->currentOrNew();
        $setting->gateway_url = $isConfigured ? FakeGateway::BASE_URL : null;
        $setting->api_token = $isConfigured ? SecretCipher::encrypt(FakeGateway::TOKEN) : null;
        $settings->saveOrFail($setting);

        return $this->fakeGateway;
    }

    /**
     * Asserts that the gateway accepted a message whose request holds every
     * field in `$expected` (`to`, `channel`, `subject`, `account`…, as sent).
     *
     * @param array<string, mixed> $expected Fields of the gateway request.
     * @param string $message Failure message.
     * @return void
     */
    protected function assertMessageSent(array $expected, string $message = ''): void
    {
        $accepted = $this->fakeGatewayOrFail()->acceptedMessages();
        foreach ($accepted as $request) {
            if (array_intersect_key($request, $expected) == $expected) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        $this->fail($message !== '' ? $message : sprintf(
            "No message matching %s was sent. Sent:\n%s",
            json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($accepted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ));
    }

    /**
     * Asserts that the gateway accepted nothing.
     *
     * @param string $message Failure message.
     * @return void
     */
    protected function assertNoMessageSent(string $message = ''): void
    {
        $this->assertSame([], $this->fakeGatewayOrFail()->acceptedMessages(), $message);
    }

    /**
     * Asserts how many messages the gateway accepted.
     *
     * @param int $count The number.
     * @param string $message Failure message.
     * @return void
     */
    protected function assertMessageCount(int $count, string $message = ''): void
    {
        $this->assertCount($count, $this->fakeGatewayOrFail()->acceptedMessages(), $message);
    }

    /**
     * @return \RockSoftware\MessagingClient\TestSuite\FakeGateway
     */
    private function fakeGatewayOrFail(): FakeGateway
    {
        if ($this->fakeGateway === null) {
            $this->fail('Call useFakeGateway() first.');
        }

        return $this->fakeGateway;
    }
}
