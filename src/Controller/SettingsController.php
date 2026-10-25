<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Controller;

use ArrayAccess;
use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use InvalidArgumentException;
use RockSoftware\MessagingClient\Gateway\GatewayException;
use RockSoftware\MessagingClient\MessagingClientPlugin;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessageStatusType;
use RockSoftware\MessagingClient\Service\Actor;
use RockSoftware\MessagingClient\Service\AppBaseUrl;
use RockSoftware\MessagingClient\Service\ConnectionCheck;
use RockSoftware\MessagingClient\Service\DeliveryGate;
use RockSoftware\MessagingClient\Service\MessagingSettings;

/**
 * Settings › Messaging: all of the application's messaging configuration —
 * the sending switch per channel, the gateway connection and the sender per
 * channel.
 *
 *   GET  /messaging-client/settings                — the page
 *   POST /messaging-client/settings/switch-sending — `channel`, `is_sending_enabled`
 *   POST /messaging-client/settings/update         — save the address, token and senders
 *   POST /messaging-client/settings/test           — check the connection, send nothing
 *   POST /messaging-client/settings/send-test      — send a test message (`isTestMessageEnabled`):
 *                                                    `recipient`, optional `channel` and `sender`
 *   POST /messaging-client/settings/senders        — `enabled[]` (codes), `default[<channel>]` (a code or empty)
 *   POST /messaging-client/settings/refresh-senders — refresh the senders from the gateway
 *
 * Who may open it is the application's decision:
 * `RockSoftwareMessagingClient.settingsAccess`, a callable receiving the
 * identity and the request, asked after the application's authentication has
 * run (a signed-out visitor is sent to sign in by it, as on any other page).
 * Without one, any signed-in user may. Nobody who is not signed in ever may:
 * with no authentication of the application's to redirect, the answer is 403.
 *
 * Events, for the application's audit log (`subject` is this controller):
 *
 * - `RockSoftwareMessagingClient.settingsChanged` — `identity`, `fields` (names
 *   of the fields changed; the token as `api_token`, never its value)
 * - `RockSoftwareMessagingClient.connectionChecked` — `identity`, `check`
 *   (`ConnectionCheck::toArray()`)
 * - `RockSoftwareMessagingClient.testMessageSent` — `identity`, `outboundMessage`
 * - `RockSoftwareMessagingClient.sendersChanged` — `identity`, `codes` (the senders whose
 *   enabled or default state changed)
 * - `RockSoftwareMessagingClient.sendingSwitched` — `identity`, `actor`
 *   (`Actor::label()`), `channel` (`ChannelType::*`), `isSendingEnabled`
 *
 * @property \Cake\Controller\Component\FlashComponent $Flash
 */
class SettingsController extends SettingsBaseController
{
    public const EVENT_SETTINGS_CHANGED = 'RockSoftwareMessagingClient.settingsChanged';

    public const EVENT_CONNECTION_CHECKED = 'RockSoftwareMessagingClient.connectionChecked';

    public const EVENT_TEST_MESSAGE_SENT = 'RockSoftwareMessagingClient.testMessageSent';

    public const EVENT_SENDING_SWITCHED = 'RockSoftwareMessagingClient.sendingSwitched';

    public const EVENT_SENDERS_CHANGED = 'RockSoftwareMessagingClient.sendersChanged';

    /**
     * Runs after the components' `startup` (priority 10), the application's
     * Authentication component among them.
     */
    public const ACCESS_CHECK_PRIORITY = 100;

    /** The page's tabs, `?tab=`; the channels tab is the default. */
    public const TAB_CHANNELS = 'channels';

    public const TAB_CONNECTION = 'connection';

    public const TAB_MESSAGES = 'messages';

    /** Where the latest connection check waits for the page, between the redirect and the next view. */
    public const SESSION_CONNECTION_CHECK = 'RockSoftwareMessagingClient.connectionCheck';

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        if (!$this->components()->has('Flash')) {
            $this->loadComponent('Flash');
        }
    }

    /**
     * Decides access at `Controller.startup`, after the application's own
     * components: its Authentication component checks the identity there and
     * sends a signed-out visitor to sign in, as on any other page. Only then is
     * `settingsAccess` asked, for a signed-in identity.
     *
     * @return array<string, mixed>
     */
    public function implementedEvents(): array
    {
        return parent::implementedEvents() + [
            'Controller.startup' => ['callable' => 'checkAccess', 'priority' => self::ACCESS_CHECK_PRIORITY],
        ];
    }

    /**
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event The event.
     * @return void
     * @throws \Cake\Http\Exception\ForbiddenException When nobody is signed in or `settingsAccess` refuses.
     */
    public function checkAccess(EventInterface $event): void
    {
        $identity = $this->identity();
        $access = MessagingClientPlugin::setting('settingsAccess');
        $isAllowed = $identity !== null
            && ($access === null || (is_callable($access) && $access($identity, $this->getRequest()) === true));
        if (!$isAllowed) {
            throw new ForbiddenException(
                __d('rock_software_messaging_client', 'You may not change the messaging settings.'),
            );
        }
    }

    /**
     * @return void
     */
    public function index(): void
    {
        $settings = new MessagingSettings();
        $session = $this->getRequest()->getSession();
        $check = $session->consume(self::SESSION_CONNECTION_CHECK);
        $check = is_array($check) ? ConnectionCheck::fromArray($check) : null;
        $tab = (string)$this->getRequest()->getQuery('tab', self::TAB_CHANNELS);
        if (!in_array($tab, [self::TAB_CHANNELS, self::TAB_CONNECTION, self::TAB_MESSAGES], true)) {
            $tab = self::TAB_CHANNELS;
        }

        $this->set([
            'tab' => $tab,
            // The page's title for an application's own breadcrumb (`breadcrumbTitle`).
            'breadcrumbTitle' => __d('rock_software_messaging_client', 'Messaging'),
            'breadcrumbParent' => MessagingClientPlugin::setting('breadcrumbParent'),
            'setting' => $settings->setting(),
            'isConfigured' => $settings->isConfigured(),
            'sendingSwitches' => DeliveryGate::switches(),
            'appBaseUrl' => AppBaseUrl::current(),
            'isTestMessageEnabled' => MessagingClientPlugin::setting('isTestMessageEnabled') !== false,
            'connectionCheck' => $check,
            'senders' => $settings->senders(),
            'testRecipient' => $this->testRecipient(),
            'outboundMessages' => $settings->latestMessages(),
        ]);
    }

    /**
     * Back to the page, on a tab.
     *
     * @param string $tab `TAB_*`.
     * @return \Cake\Http\Response|null
     */
    private function backTo(string $tab): ?Response
    {
        return $this->redirect($tab === self::TAB_CHANNELS
            ? ['action' => 'index']
            : ['action' => 'index', '?' => ['tab' => $tab]]);
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function update(): ?Response
    {
        $this->getRequest()->allowMethod('post');
        $settings = new MessagingSettings();
        $data = (array)$this->getRequest()->getData();
        $setting = $settings->save($data, $changed);
        if ($setting->hasErrors()) {
            $messages = [];
            foreach ($setting->getErrors() as $errors) {
                foreach ((array)$errors as $error) {
                    $messages[] = (string)$error;
                }
            }
            $this->Flash->error(__d(
                'rock_software_messaging_client',
                'Not saved. {0}',
                implode(' ', array_unique($messages)),
            ));

            return $this->backTo(self::TAB_CONNECTION);
        }

        if ($changed !== []) {
            $this->dispatch(self::EVENT_SETTINGS_CHANGED, ['fields' => $changed]);
        }
        $this->Flash->success(__d(
            'rock_software_messaging_client',
            'Saved. Use "Test connection" to confirm the gateway accepts the token.',
        ));

        return $this->backTo(self::TAB_CONNECTION);
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function test(): ?Response
    {
        $this->getRequest()->allowMethod('post');
        $check = (new MessagingSettings())->checkConnection();
        $this->getRequest()->getSession()->write(self::SESSION_CONNECTION_CHECK, $check->toArray());
        $this->dispatch(self::EVENT_CONNECTION_CHECKED, ['check' => $check->toArray()]);

        return $this->backTo(self::TAB_CONNECTION);
    }

    /**
     * Saves which senders are enabled and each channel's default.
     *
     * @return \Cake\Http\Response|null
     */
    public function senders(): ?Response
    {
        $this->getRequest()->allowMethod('post');
        try {
            $changed = (new MessagingSettings())->saveSenders((array)$this->getRequest()->getData());
        } catch (InvalidArgumentException $exception) {
            $this->Flash->error($exception->getMessage());

            return $this->backTo(self::TAB_CHANNELS);
        }
        if ($changed !== []) {
            $this->dispatch(self::EVENT_SENDERS_CHANGED, ['codes' => $changed]);
        }
        $this->Flash->success(__d('rock_software_messaging_client', 'The senders are saved.'));

        return $this->backTo(self::TAB_CHANNELS);
    }

    /**
     * Refreshes the senders from the gateway.
     *
     * @return \Cake\Http\Response|null
     */
    public function refreshSenders(): ?Response
    {
        $this->getRequest()->allowMethod('post');
        try {
            (new MessagingSettings())->refreshSenders();
            $this->Flash->success(__d('rock_software_messaging_client', 'The senders are refreshed from the gateway.'));
        } catch (InvalidArgumentException | GatewayException $exception) {
            $this->Flash->error($exception->getMessage());
        }

        return $this->backTo(self::TAB_CHANNELS);
    }

    /**
     * Switches sending on one channel on or off. On binds it to this
     * application's address (`App.fullBaseUrl`).
     *
     * @return \Cake\Http\Response|null
     */
    public function switchSending(): ?Response
    {
        $this->getRequest()->allowMethod('post');
        $channel = (string)$this->getRequest()->getData('channel', '');
        $isOn = filter_var($this->getRequest()->getData('is_sending_enabled'), FILTER_VALIDATE_BOOLEAN);
        $actor = Actor::label($this->identity());

        try {
            $switch = (new MessagingSettings())->switchSending($channel, $isOn, $actor);
        } catch (InvalidArgumentException $exception) {
            $this->Flash->error($exception->getMessage());

            return $this->backTo(self::TAB_CHANNELS);
        }
        $this->dispatch(self::EVENT_SENDING_SWITCHED, [
            'actor' => $actor,
            'channel' => $channel,
            'isSendingEnabled' => $isOn,
        ]);
        $this->Flash->success($switch->isOn()
            ? __d(
                'rock_software_messaging_client',
                'Sending {0} is on, at {1} only.',
                ChannelType::label($channel),
                (string)$switch->currentUrl,
            )
            : __d('rock_software_messaging_client', 'Sending {0} is off.', ChannelType::label($channel)));

        return $this->backTo(self::TAB_CHANNELS);
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function sendTest(): ?Response
    {
        $this->getRequest()->allowMethod('post');
        if (MessagingClientPlugin::setting('isTestMessageEnabled') === false) {
            throw new NotFoundException();
        }
        $recipient = trim((string)$this->getRequest()->getData('recipient', ''));
        if ($recipient === '') {
            $recipient = (string)$this->testRecipient();
        }

        try {
            $message = (new MessagingSettings())->sendTestMessage(
                $recipient,
                (string)$this->getRequest()->getData('channel', '') ?: null,
                (string)$this->getRequest()->getData('sender', '') ?: null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->Flash->error($exception->getMessage());

            return $this->backTo(self::TAB_CONNECTION);
        }
        $this->dispatch(self::EVENT_TEST_MESSAGE_SENT, ['outboundMessage' => $message]);

        $error = (string)$message->error;
        match ($message->statusValue()) {
            OutboundMessageStatusType::ACCEPTED => $this->Flash->success($message->is_test
                ? __d(
                    'rock_software_messaging_client',
                    'The gateway accepted a test message to {0}. It is trapped there: the token or the sender is '
                    . 'in test mode, so it reaches no one.',
                    $message->recipient,
                )
                : __d(
                    'rock_software_messaging_client',
                    'The gateway accepted a test message to {0}.',
                    $message->recipient,
                )),
            OutboundMessageStatusType::NOT_SENT_DELIVERY_OFF => $this->Flash->warning(__d(
                'rock_software_messaging_client',
                'Not sent: sending {0} is switched off in this application. '
                . 'Test the connection instead; it sends nothing.',
                ChannelType::label((string)$message->channelValue()),
            )),
            OutboundMessageStatusType::PENDING => $this->Flash->warning(__d(
                'rock_software_messaging_client',
                'Not sent yet: {0} It is tried again shortly.',
                $error,
            )),
            default => $this->Flash->error(__d(
                'rock_software_messaging_client',
                'The test message was not sent: {0}',
                $error,
            )),
        };

        return $this->backTo(self::TAB_CONNECTION);
    }

    /**
     * The signed-in identity, as the application's authentication left it.
     *
     * @return mixed
     */
    private function identity(): mixed
    {
        return $this->getRequest()->getAttribute('identity');
    }

    /**
     * The address the test message is offered to.
     *
     * @return string|null
     */
    private function testRecipient(): ?string
    {
        $identity = $this->identity();
        $setting = MessagingClientPlugin::setting('testRecipient');
        if (is_callable($setting)) {
            $address = $setting($identity);
        } elseif ($identity instanceof ArrayAccess || is_array($identity)) {
            $address = $identity['email'] ?? null;
        } else {
            $address = is_object($identity) && method_exists($identity, 'get') ? $identity->get('email') : null;
        }

        return is_string($address) && trim($address) !== '' ? trim($address) : null;
    }

    /**
     * @param string $name The event.
     * @param array<string, mixed> $data Its data; the identity is added.
     * @return void
     */
    private function dispatch(string $name, array $data): void
    {
        EventManager::instance()->dispatch(new Event($name, $this, ['identity' => $this->identity()] + $data));
    }
}
