<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\I18n\I18n;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Cake\Utility\Hash;
use RockSoftware\MessagingClient\Controller\SettingsController;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessageStatusType;
use RockSoftware\MessagingClient\Model\Entity\Setting;
use RockSoftware\MessagingClient\Service\DeliveryGate;
use RockSoftware\MessagingClient\Service\MessagingSettings;
use RockSoftware\MessagingClient\Service\SecretCipher;
use RockSoftware\MessagingClient\TestSuite\Fixture\MessagingClientFixtures;
use RockSoftware\MessagingClient\TestSuite\MessagingTestTrait;
use RockSoftware\Settings\Settings;
use RockSoftware\Settings\SettingsPlugin;

class SettingsControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use MessagingTestTrait;

    protected array $fixtures = MessagingClientFixtures::ALL;

    /**
     * @var list<\Cake\Event\EventInterface<mixed>>
     */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();
        Configure::write(
            'RockSoftwareMessagingClient.settingsAccess',
            fn($identity): bool => $identity['email'] === 'admin@example.test',
        );
        foreach (
            [
                SettingsController::EVENT_SETTINGS_CHANGED,
                SettingsController::EVENT_CONNECTION_CHECKED,
                SettingsController::EVENT_TEST_MESSAGE_SENT,
                SettingsController::EVENT_SENDING_SWITCHED,
                SettingsController::EVENT_SENDERS_CHANGED,
            ] as $name
        ) {
            EventManager::instance()->on($name, function (EventInterface $event): void {
                $this->events[] = $event;
            });
        }
    }

    protected function tearDown(): void
    {
        foreach (
            [
                SettingsController::EVENT_SETTINGS_CHANGED,
                SettingsController::EVENT_CONNECTION_CHECKED,
                SettingsController::EVENT_TEST_MESSAGE_SENT,
                SettingsController::EVENT_SENDING_SWITCHED,
                SettingsController::EVENT_SENDERS_CHANGED,
            ] as $name
        ) {
            EventManager::instance()->off($name);
        }
        parent::tearDown();
    }

    public function testNobodyWhoIsNotSignedInMayOpenIt(): void
    {
        Configure::write('RockSoftwareMessagingClient.settingsAccess', null);

        $this->post('/messaging-client/settings/test');

        $this->assertResponseCode(403);
    }

    public function testASignedOutVisitorIsSentToSignInByTheApplication(): void
    {
        // What the application's Authentication component does at Controller.startup.
        $signIn = function (EventInterface $event): void {
            /** @var \Cake\Controller\Controller $controller */
            $controller = $event->getSubject();
            if ($controller->getRequest()->getAttribute('identity') === null) {
                $event->setResult($controller->redirect('/sign-in?redirect=/messaging-client/settings'));
                $event->stopPropagation();
            }
        };
        EventManager::instance()->on('Controller.startup', $signIn);

        try {
            $this->get('/messaging-client/settings');
            $this->assertRedirect('/sign-in?redirect=/messaging-client/settings');

            $this->session(['Auth' => ['id' => 2, 'email' => 'someone@example.test']]);
            $this->get('/messaging-client/settings');
            $this->assertResponseCode(403);
        } finally {
            EventManager::instance()->off('Controller.startup', $signIn);
        }
    }

    public function testAccessIsTheApplicationsDecision(): void
    {
        $this->session(['Auth' => ['id' => 2, 'email' => 'someone@example.test']]);

        $this->post('/messaging-client/settings/test');

        $this->assertResponseCode(403);
    }

    public function testSavingEncryptsTheTokenAndNeverEchoesIt(): void
    {
        $this->signIn();

        $this->post('/messaging-client/settings/update', [
            'gateway_url' => 'https://messaging.example.test',
            'api_token' => 'secret-token',
        ]);

        $this->assertRedirect('/messaging-client/settings?tab=connection');
        $this->assertFlashElement('flash/success');
        $setting = $this->setting();
        $this->assertSame('https://messaging.example.test', $setting->gateway_url);
        $this->assertNotSame('secret-token', $setting->api_token);
        $this->assertSame('secret-token', SecretCipher::decrypt((string)$setting->api_token));
        $this->assertStringNotContainsString('secret-token', print_r($setting, true));
        $this->assertSame(['gateway_url', 'api_token'], $this->events[0]->getData('fields'));
    }

    public function testAnEmptyTokenFieldKeepsTheStoredTokenAndRemoveRemovesIt(): void
    {
        $this->signIn();
        $this->post('/messaging-client/settings/update', [
            'gateway_url' => 'https://messaging.example.test',
            'api_token' => 'secret-token',
        ]);

        $this->post('/messaging-client/settings/update', [
            'gateway_url' => 'https://msg-dev.example.test',
            'api_token' => '',
        ]);
        $this->assertSame('secret-token', SecretCipher::decrypt((string)$this->setting()->api_token));
        $this->assertSame(['gateway_url'], $this->events[1]->getData('fields'));

        $this->post('/messaging-client/settings/update', [
            'gateway_url' => 'https://msg-dev.example.test',
            'api_token' => 'ignored',
            'remove_api_token' => '1',
        ]);
        $this->assertFalse($this->setting()->hasToken());
    }

    public function testAnAddressThatIsNotAPlainHttpsHostIsNotSaved(): void
    {
        $this->signIn();

        $this->post('/messaging-client/settings/update', [
            'gateway_url' => 'http://messaging.example.test/path',
            'api_token' => 'secret-token',
        ]);

        $this->assertRedirect('/messaging-client/settings?tab=connection');
        $this->assertFlashElement('flash/error');
        $this->assertFlashMessageAt(0, 'Not saved. Enter the gateway as https:// and a host name only, '
            . 'such as https://messaging.example.com.');
        $this->assertNull($this->fetchTable('RockSoftware/MessagingClient.Settings')->find()->first());
        $this->assertSame([], $this->events);
    }

    public function testTestingTheConnectionSendsNothingAndKeepsTheResultForThePage(): void
    {
        $gateway = $this->useFakeGateway(isDeliveryEnabled: false);
        $gateway->status['token']['is_test'] = true;
        $this->signIn();

        $this->post('/messaging-client/settings/test');

        $this->assertRedirect('/messaging-client/settings?tab=connection');
        $check = Hash::get($_SESSION, SettingsController::SESSION_CONNECTION_CHECK);
        $this->assertIsArray($check);
        $this->assertSame(['email' => false, 'sms' => false], $check['sendingEnabledChannels']);
        $this->assertTrue($check['status']['isTestToken']);
        $this->assertSame('test-app-email', $check['connections'][0]['code']);
        $this->assertSame(['GET', 'GET'], array_column($gateway->requests, 'method'));
        $this->assertSame($check, $this->events[0]->getData('check'));
    }

    public function testATestMessageGoesToTheGivenAddressAndIsLogged(): void
    {
        $gateway = $this->useFakeGateway();
        $this->signIn();

        $this->post('/messaging-client/settings/send-test', ['recipient' => 'user@example.test']);

        $this->assertRedirect('/messaging-client/settings?tab=connection');
        $this->assertFlashElement('flash/success');
        $this->assertFlashMessage('The gateway accepted a test message to user@example.test.');
        $this->assertMessageSent([
            'channel' => 'email',
            'to' => 'user@example.test',
            'subject' => 'Test message from Test App',
            'auto_submitted' => true,
        ]);
        $this->assertStringStartsWith('messaging-client-test-', $gateway->acceptedMessages()[0]['external_ref']);
        $message = $this->events[0]->getData('outboundMessage');
        $this->assertSame(OutboundMessageStatusType::ACCEPTED, $message->statusValue());
    }

    public function testTheTestMessageNamesTheApplicationByTheSettingsPlugin(): void
    {
        $this->useFakeGateway();
        $this->signIn();
        Settings::set(SettingsPlugin::APPLICATION_NAME, 'Example Console');
        Configure::write('RockSoftwareMessagingClient.appName', 'Old Name');

        try {
            $this->post('/messaging-client/settings/send-test', ['recipient' => 'user@example.test']);
        } finally {
            Settings::set(SettingsPlugin::APPLICATION_NAME, 'Test App');
            Configure::delete('RockSoftwareMessagingClient.appName');
        }

        $this->assertMessageSent([
            'channel' => 'email',
            'to' => 'user@example.test',
            'subject' => 'Test message from Example Console',
        ]);
    }

    public function testATestMessageDefaultsToTheSignedInUsersOwnAddress(): void
    {
        $this->useFakeGateway();
        $this->signIn();

        $this->post('/messaging-client/settings/send-test', ['recipient' => '']);

        $this->assertMessageSent(['to' => 'admin@example.test']);
    }

    public function testAPhoneNumberGetsAnSms(): void
    {
        $this->useFakeGateway();
        $this->signIn();

        $this->post('/messaging-client/settings/send-test', ['recipient' => '040 123 4567']);

        $this->assertMessageSent(['channel' => 'sms', 'to' => '+358401234567']);
    }

    public function testWithDeliveryOffTheTestMessageIsNotSent(): void
    {
        $gateway = $this->useFakeGateway(isDeliveryEnabled: false);
        $this->signIn();

        $this->post('/messaging-client/settings/send-test', ['recipient' => 'user@example.test']);

        $this->assertFlashElement('flash/warning');
        $this->assertSame([], $gateway->requests);
    }

    public function testARefusedTestMessageSaysWhy(): void
    {
        $this->useFakeGateway()->refuse(403, 'Token lacks permission "message.send"');
        $this->signIn();

        $this->post('/messaging-client/settings/send-test', ['recipient' => 'user@example.test']);

        $this->assertFlashElement('flash/error');
        $this->assertFlashMessageContains('Token lacks permission');
    }

    public function testNotConfiguredThePageSaysSoOnEveryTab(): void
    {
        $this->signIn();

        $this->get('/messaging-client/settings');
        $this->assertResponseOk();
        $this->assertSame('channels', $this->viewVariable('tab'));
        $this->assertResponseContains('Connection not set up');
        $this->assertResponseContains('Email off');
        $this->assertResponseContains('Text messages off');
        $this->assertResponseContains('Never switched on');
        $this->assertResponseContains('No senders yet.');
        $this->assertResponseContains('Messages recorded while sending is off are not sent afterwards.');
        $this->assertResponseContains('aria-checked="false"');

        $this->get('/messaging-client/settings?tab=connection');
        $this->assertResponseContains('name="gateway_url"');
        $this->assertResponseContains('Not stored yet');
        $this->assertResponseContains('aria-current="page">Connection');

        $this->get('/messaging-client/settings?tab=messages');
        $this->assertResponseContains('Nothing has been sent yet.');
    }

    public function testConfiguredTheChannelsTabListsTheSendersAndNeverTheToken(): void
    {
        $this->useFakeGateway(isConfigured: false);
        $this->storeSettings('https://messaging.example.test', 'secret-token', 'test-app-email');
        (new MessagingSettings())->refreshSenders();
        $this->signIn();

        $this->get('/messaging-client/settings');

        $this->assertResponseOk();
        $this->assertResponseNotContains('secret-token');
        $this->assertResponseContains('Email on');
        $this->assertResponseContains('aria-checked="true"');
        $this->assertResponseContains('Switched on by test');
        $this->assertResponseContains('Sending is on only at app.example.test.');
        $this->assertResponseContains('name="default[email]" value="test-app-email" checked');
        $this->assertResponseContains('name="enabled[]" value="test-app-sms" checked');
        $this->assertResponseContains('Live');

        $this->get('/messaging-client/settings?tab=connection');
        $this->assertResponseNotContains('secret-token');
        $this->assertResponseContains('Stored · type a new one to replace it');
        $this->assertResponseContains('name="remove_api_token"');
        $this->assertResponseContains('value="admin@example.test"');
        $this->assertResponseContains('<option value="test-app-sms">');
    }

    public function testAfterTheTestThePillsAndTheConnectionTabShowTheGatewaysAnswer(): void
    {
        $gateway = $this->useFakeGateway();
        $gateway->status['token']['is_test'] = true;
        $this->signIn();
        $this->post('/messaging-client/settings/test');

        $this->get('/messaging-client/settings?tab=connection');

        $this->assertResponseContains('Connection works: test token');
        $this->assertResponseContains('The token test-app is valid and may send.');
        $this->assertResponseContains('It is a test token');
    }

    public function testAFailedTestIsShownUntilTheNextOne(): void
    {
        $this->useFakeGateway()->refuse(401, 'Unknown or revoked token');
        $this->signIn();
        $this->post('/messaging-client/settings/test');

        $this->get('/messaging-client/settings?tab=connection');
        $this->get('/messaging-client/settings?tab=connection');

        $this->assertResponseContains('Connection not working');
        $this->assertResponseContains('HTTP 401');
    }

    public function testAChannelOffOrSwitchedOnElsewhereSaysSo(): void
    {
        $this->useFakeGateway(isDeliveryEnabled: false);
        /** @var \RockSoftware\MessagingClient\Model\Table\ChannelSettingsTable $switches */
        $switches = $this->fetchTable('RockSoftware/MessagingClient.ChannelSettings');
        $switches->switchSending(ChannelType::SMS, true, 'https://production.example.test', 'Test User');
        $this->signIn();
        $this->post('/messaging-client/settings/send-test', ['recipient' => 'user@example.test']);

        $this->get('/messaging-client/settings');
        $this->assertResponseContains('Email off');
        $this->assertResponseContains('Text messages on at another address');
        $this->assertResponseContains('Switched on at https://production.example.test, so it is off here.');

        $this->get('/messaging-client/settings?tab=messages');
        $this->assertResponseContains('Not sent: delivery is off');
        $this->assertResponseContains('user@example.test');
    }

    public function testATrappedMessageAndAnUnavailableSenderAreMarked(): void
    {
        $gateway = $this->useFakeGateway();
        $gateway->isTrapping = true;
        $this->signIn();
        $this->post('/messaging-client/settings/refresh-senders');
        $this->post('/messaging-client/settings/send-test', ['recipient' => 'user@example.test']);
        array_pop($gateway->connections);
        $this->post('/messaging-client/settings/refresh-senders');

        $this->get('/messaging-client/settings?tab=messages');
        $this->assertResponseContains('Accepted, trapped as a test');
        $this->assertResponseContains('Test message from Test App');

        $this->get('/messaging-client/settings');
        $this->assertResponseContains('No longer at the gateway');
    }

    public function testSwitchingSendingOnBindsItToThisAddress(): void
    {
        $this->useFakeGateway(isDeliveryEnabled: false);
        $this->session(['Auth' => ['id' => 1, 'email' => 'admin@example.test', 'name' => 'Test User']]);

        $this->post('/messaging-client/settings/switch-sending', ['channel' => 'email', 'is_sending_enabled' => '1']);

        $this->assertRedirect('/messaging-client/settings');
        $this->assertFlashMessage('Sending Email is on, at https://app.example.test only.');
        $switch = DeliveryGate::switchOf(ChannelType::EMAIL);
        $this->assertTrue($switch->isOn());
        $this->assertSame('https://app.example.test', $switch->enabledForUrl);
        $this->assertSame('Test User', $switch->changedBy);
        $this->assertNotNull($switch->changedAt);
        $this->assertFalse(DeliveryGate::isEnabledFor(ChannelType::SMS));
        $this->assertSame(
            ['actor' => 'Test User', 'channel' => 'email', 'isSendingEnabled' => true],
            array_intersect_key($this->events[0]->getData(), ['actor' => 1, 'channel' => 1, 'isSendingEnabled' => 1]),
        );
        $this->assertSame(SettingsController::EVENT_SENDING_SWITCHED, $this->events[0]->getName());

        $this->post('/messaging-client/settings/switch-sending', ['channel' => 'email', 'is_sending_enabled' => '0']);
        $this->assertFalse(DeliveryGate::isEnabledFor(ChannelType::EMAIL));
        $this->assertNull(DeliveryGate::switchOf(ChannelType::EMAIL)->enabledForUrl);
        $this->assertFalse($this->events[1]->getData('isSendingEnabled'));
    }

    public function testTheConnectionTestRefreshesTheSenders(): void
    {
        $this->useFakeGateway();
        $this->signIn();

        $this->post('/messaging-client/settings/test');

        $senders = (new MessagingSettings())->senders();
        $this->assertSame(['test-app-email'], array_map(fn($sender) => $sender->code, $senders['email']));
        $this->assertSame(['test-app-sms'], array_map(fn($sender) => $sender->code, $senders['sms']));
    }

    public function testSendersAreEnabledAndGivenADefaultPerChannel(): void
    {
        $gateway = $this->useFakeGateway();
        $gateway->connections[] = [
            'code' => 'test-app-email-2',
            'channel' => 'email',
            'identity' => 'other@example.test',
            'outbound_enabled' => true,
        ];
        $this->signIn();
        $this->post('/messaging-client/settings/refresh-senders');
        $this->assertFlashElement('flash/success');

        $this->post('/messaging-client/settings/senders', [
            'enabled' => ['test-app-email', 'test-app-sms'],
            'default' => ['email' => 'test-app-email', 'sms' => ''],
        ]);

        $this->assertRedirect('/messaging-client/settings');
        $this->assertFlashElement('flash/success');
        $senders = (new MessagingSettings())->senders();
        $email = array_column(array_map(fn($s) => [$s->code, [$s->is_enabled, $s->is_default]], $senders['email']), 1, 0);
        $this->assertSame(
            ['test-app-email' => [true, true], 'test-app-email-2' => [false, false]],
            $email,
        );
        $this->assertFalse($senders['sms'][0]->is_default);
        $this->assertSame(SettingsController::EVENT_SENDERS_CHANGED, $this->events[0]->getName());
        $this->assertSame(['test-app-email', 'test-app-email-2'], $this->events[0]->getData('codes'));
    }

    public function testADefaultSenderMustBeEnabled(): void
    {
        $this->useFakeGateway();
        $this->signIn();
        $this->post('/messaging-client/settings/refresh-senders');

        $this->post('/messaging-client/settings/senders', [
            'enabled' => [],
            'default' => ['email' => 'test-app-email'],
        ]);

        $this->assertFlashElement('flash/error');
        $this->assertFalse((new MessagingSettings())->senders()['email'][0]->is_default);
    }

    public function testATestMessageMayNameItsSender(): void
    {
        $this->useFakeGateway();
        $this->signIn();
        $this->post('/messaging-client/settings/refresh-senders');

        $this->post('/messaging-client/settings/send-test', [
            'recipient' => '040 123 4567',
            'channel' => 'sms',
            'sender' => 'test-app-sms',
        ]);

        $this->assertMessageSent(['channel' => 'sms', 'account' => 'test-app-sms']);
    }

    public function testSwitchingIsForThoseWhoMayOpenThePage(): void
    {
        $this->useFakeGateway(isDeliveryEnabled: false);
        $this->session(['Auth' => ['id' => 2, 'email' => 'someone@example.test']]);

        $this->post('/messaging-client/settings/switch-sending', ['channel' => 'email', 'is_sending_enabled' => '1']);

        $this->assertResponseCode(403);
        $this->assertFalse(DeliveryGate::isEnabledFor(ChannelType::EMAIL));
    }

    public function testAnUnknownChannelIsNotSwitched(): void
    {
        $this->signIn();

        $this->post('/messaging-client/settings/switch-sending', ['channel' => 'fax', 'is_sending_enabled' => '1']);

        $this->assertFlashElement('flash/error');
        $this->assertSame([], $this->events);
    }

    public function testTheTestMessageCanBeTurnedOff(): void
    {
        Configure::write('RockSoftwareMessagingClient.isTestMessageEnabled', false);
        $gateway = $this->useFakeGateway();
        $this->signIn();

        $this->get('/messaging-client/settings?tab=connection');
        $this->assertResponseOk();
        $this->assertResponseNotContains('name="recipient"');
        $this->assertFalse($this->viewVariable('isTestMessageEnabled'));

        $gateway->requests = [];
        $this->post('/messaging-client/settings/send-test', ['recipient' => 'user@example.test']);
        $this->assertResponseCode(404);
        $this->assertSame([], $gateway->requests);
    }

    public function testThePageFitsTheApplicationsBreadcrumb(): void
    {
        $this->signIn();

        $this->get('/messaging-client/settings');
        $this->assertSame('Messaging', $this->viewVariable('breadcrumbTitle'));
        $this->assertResponseNotContains('aria-label="Breadcrumb"');

        Configure::write(
            'RockSoftwareMessagingClient.breadcrumbParent',
            ['title' => 'Settings', 'url' => '/settings'],
        );
        $this->get('/messaging-client/settings');
        $this->assertResponseContains('<nav aria-label="Breadcrumb" class="rsmc-breadcrumb">');
        $this->assertResponseContains('<a href="/settings">Settings</a>');
        $this->assertResponseContains('<span aria-current="page">Messaging</span>');
    }

    public function testThePageIsInFinnishForAFinnishApplication(): void
    {
        I18n::setLocale('fi_FI');
        $this->signIn();

        try {
            $this->get('/messaging-client/settings');
            $this->assertResponseContains('Kanavat ja lähettäjät');
            $this->assertResponseContains('Pois päältä -aikana syntyneitä viestejä ei lähetetä jälkikäteen.');
            $this->get('/messaging-client/settings?tab=connection');
        } finally {
            I18n::setLocale('en_US');
        }

        $this->assertResponseContains('Viestintä');
        $this->assertResponseContains('Testaa yhteys');
        $this->assertResponseContains('Lähetä testiviesti');
    }

    /**
     * @param string $url The gateway address.
     * @param string $token The token.
     * @param string|null $emailAccount The email sender.
     * @return void
     */
    private function storeSettings(string $url, string $token, ?string $emailAccount = null): void
    {
        /** @var \RockSoftware\MessagingClient\Model\Table\SettingsTable $table */
        $table = $this->fetchTable('RockSoftware/MessagingClient.Settings');
        $setting = $table->patchEntity($table->currentOrNew(), ['gateway_url' => $url]);
        $setting->email_account = $emailAccount;
        $setting->api_token = SecretCipher::encrypt($token);
        $table->saveOrFail($setting);
    }

    /**
     * @return void
     */
    private function signIn(): void
    {
        $this->session(['Auth' => ['id' => 1, 'email' => 'admin@example.test']]);
    }

    /**
     * @return \RockSoftware\MessagingClient\Model\Entity\Setting
     */
    private function setting(): Setting
    {
        /** @var \RockSoftware\MessagingClient\Model\Table\SettingsTable $table */
        $table = $this->fetchTable('RockSoftware/MessagingClient.Settings');
        $setting = $table->current();
        $this->assertNotNull($setting);

        return $setting;
    }
}
