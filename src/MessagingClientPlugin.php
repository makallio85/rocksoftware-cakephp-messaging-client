<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\PluginApplicationInterface;
use Cake\I18n\I18n;
use Cake\I18n\Package;
use Cake\I18n\Parser\PoFileParser;
use Cake\Routing\RouteBuilder;
use RockSoftware\MessagingClient\Command\DeliverCommand;

/**
 * Email and SMS through the messaging gateway's public API
 * (`/public-api/v1`), plug and play: loading the plugin and running its
 * migration is enough. The plugin owns all messaging configuration — the
 * gateway connection, the sender per channel and the sending switches — on
 * its own settings page; an application only sends through `Messenger`.
 * Every setting below has a default in `DEFAULTS`.
 *
 * Routes, all under `/messaging-client`:
 *
 * - `GET /messaging-client/health` — health for a monitor, no sign-in
 * - `GET /messaging-client/settings` — Settings › Messaging
 * - `POST /messaging-client/settings/update` — save the gateway address, token and senders
 * - `POST /messaging-client/settings/test` — check the connection, send nothing
 * - `POST /messaging-client/settings/send-test` — send a test message
 * - `POST /messaging-client/settings/switch-sending` — switch a channel's sending on or off
 * - `POST /messaging-client/settings/senders` — enable senders, choose each channel's default
 * - `POST /messaging-client/settings/refresh-senders` — refresh the senders from the gateway
 *
 * Command: `bin/cake messaging_client deliver`, run from the application's
 * worker loop, resends the outbound messages still waiting.
 */
class MessagingClientPlugin extends BasePlugin
{
    /**
     * Every setting and its default. An application overrides any of them in
     * `Configure::write('RockSoftwareMessagingClient', [...])` (usually
     * `config/app.php`).
     *
     * - `isDeliveryEnabled`: an override that can only force sending off:
     *   `false`, or a callable receiving the channel (`ChannelType::*`) and
     *   returning `false`. Whether a channel sends is otherwise its switch on
     *   the settings page (`Service\DeliveryGate`); nothing here can turn one on.
     * - `defaultCountryCode`: the country code an SMS recipient written without
     *   one gets (`PhoneNumber`).
     * - `healthFailureThreshold`: how many failed messages in the last hour make
     *   the health endpoint report `recent_failures`.
     * - `breadcrumbParent`: the page above the settings page in an application
     *   whose pages write their breadcrumb in the template: a title, or
     *   `['title' => …, 'url' => …]`. The page then shows "parent › Messaging".
     *   Null for none; an application whose layout builds the breadcrumb
     *   (`breadcrumb` view variable) lists the page in its own menu.
     * - `isTestMessageEnabled`: whether the settings page offers a test message.
     * - `settingsAccess`: who may open the settings page; a callable receiving
     *   the identity and the request. Null means any signed-in user.
     * - `testRecipient`: the address the settings page's test message is
     *   offered to; a callable receiving the identity and returning a string or
     *   null. Null reads the identity's `email`.
     * - `retryDelays`: seconds to wait before each further attempt of a message
     *   that met a temporary error. When they run out, the message ends failed.
     * - `deliverBatchSize`: how many waiting messages one `deliver` run sends.
     * - `latestMessagesLimit`: how many outbound messages the settings page lists.
     * - `httpClient`: the `Cake\Http\Client` the gateway client uses; null for a
     *   new one. Tests put a `TestSuite\FakeGateway` here.
     * - `locale`: the language of the plugin's texts when it differs from the
     *   application's locale (e.g. `fi`); null follows the application.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'isDeliveryEnabled' => null,
        'isTestMessageEnabled' => true,
        'breadcrumbParent' => null,
        'defaultCountryCode' => '+358',
        'healthFailureThreshold' => 5,
        'settingsAccess' => null,
        'testRecipient' => null,
        'retryDelays' => [60, 300, 900, 1800, 3600, 7200, 14400],
        'deliverBatchSize' => 50,
        'latestMessagesLimit' => 20,
        'httpClient' => null,
        'locale' => null,
    ];

    /** The settings key under `Configure`. */
    public const CONFIG_KEY = 'RockSoftwareMessagingClient';

    /** The translation domain of every text the plugin shows. */
    public const TRANSLATION_DOMAIN = 'rock_software_messaging_client';

    protected ?string $name = 'RockSoftware/MessagingClient';

    protected bool $middlewareEnabled = false;

    protected bool $bootstrapEnabled = true;

    /**
     * Fills in every setting the application left out.
     *
     * @param \Cake\Core\PluginApplicationInterface $app The application.
     * @return void
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        self::applyDefaults();
        self::loadTranslations();
    }

    /**
     * @return void
     */
    public static function applyDefaults(): void
    {
        Configure::write(
            self::CONFIG_KEY,
            array_replace(self::DEFAULTS, (array)Configure::read(self::CONFIG_KEY, [])),
        );
    }

    /**
     * One setting, or its default when the application has not set it.
     *
     * @param string $key A key of `DEFAULTS`.
     * @return mixed
     */
    public static function setting(string $key): mixed
    {
        $settings = (array)Configure::read(self::CONFIG_KEY, []);

        return array_key_exists($key, $settings) ? $settings[$key] : (self::DEFAULTS[$key] ?? null);
    }

    /**
     * Registers the plugin's texts (domain `rock_software_messaging_client`)
     * from its own `resources/locales`. CakePHP finds a plugin's catalogue by
     * domain name only for plugins without a vendor prefix, so the plugin
     * registers it.
     *
     * @return void
     */
    public static function loadTranslations(): void
    {
        $path = dirname(__DIR__) . DS . 'resources' . DS . 'locales' . DS;
        I18n::config(self::TRANSLATION_DOMAIN, function (string $domain, string $locale) use ($path): Package {
            $fixed = self::setting('locale');
            $locale = is_string($fixed) && $fixed !== '' ? $fixed : $locale;
            $messages = [];
            foreach ([$locale, substr($locale, 0, 2)] as $folder) {
                $file = $path . $folder . DS . self::TRANSLATION_DOMAIN . '.po';
                if (is_file($file)) {
                    $messages = (new PoFileParser())->parse($file);
                    break;
                }
            }

            return new Package('default', null, $messages);
        });
    }

    /**
     * @param \Cake\Routing\RouteBuilder $routes The route builder.
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->plugin(
            'RockSoftware/MessagingClient',
            ['path' => '/messaging-client'],
            function (RouteBuilder $builder): void {
                $builder->connect('/health', ['controller' => 'Health', 'action' => 'index']);
                $builder->connect('/settings', ['controller' => 'Settings', 'action' => 'index']);
                $builder->connect('/settings/update', ['controller' => 'Settings', 'action' => 'update']);
                $builder->connect('/settings/test', ['controller' => 'Settings', 'action' => 'test']);
                $builder->connect('/settings/send-test', ['controller' => 'Settings', 'action' => 'sendTest']);
                $builder->connect('/settings/senders', ['controller' => 'Settings', 'action' => 'senders']);
                $builder->connect(
                    '/settings/refresh-senders',
                    ['controller' => 'Settings', 'action' => 'refreshSenders'],
                );
                $builder->connect(
                    '/settings/switch-sending',
                    ['controller' => 'Settings', 'action' => 'switchSending'],
                );
            },
        );
    }

    /**
     * @param \Cake\Console\CommandCollection $commands The command collection.
     * @return \Cake\Console\CommandCollection
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        return $commands->add(DeliverCommand::defaultName(), DeliverCommand::class);
    }
}
