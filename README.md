# rocksoftware-cakephp-messaging-client

A CakePHP 5 plugin through which an application sends email and SMS via the messaging gateway's
public API (`/public-api/v1`). It gives the application a send-once guarantee, sending switches,
a delivery log and a settings page.

**The plugin owns all messaging configuration.** The plugin's own settings page holds:

- the gateway connection: the gateway's address and the API token;
- the senders of each channel;
- the sending switch of each channel.

An application never configures messaging itself; it only sends through `Messenger`.

| | Name |
|---|---|
| Composer package | `makallio85/rocksoftware-cakephp-messaging-client` |
| CakePHP plugin, namespace | `RockSoftware/MessagingClient`, `RockSoftware\MessagingClient\` |
| Routes | `/messaging-client/*` |
| Settings, events | `RockSoftwareMessagingClient.*` |
| Tables | `rock_software_messaging_client_*` |
| Translation domain | `rock_software_messaging_client` (English msgids, Finnish `.po`) |
| Commands | `bin/cake messaging_client …` |

## Installing in an application

1. **Composer.** Run `composer require makallio85/rocksoftware-cakephp-messaging-client:^1.1`. It
   brings [`makallio85/rocksoftware-cakephp-settings`](https://github.com/makallio85/rocksoftware-cakephp-settings),
   whose `application.name` names the application in the test message.
2. **Plugins.** Add the settings plugin (installed as its README says) and this one to
   `Application::bootstrap()`:

   ```php
   $this->addPlugin(\RockSoftware\Settings\SettingsPlugin::class);
   $this->addPlugin(\RockSoftware\MessagingClient\MessagingClientPlugin::class);
   ```
3. **Migrations.** Run `bin/cake migrations migrate -p RockSoftware/MessagingClient` in the deploy
   entrypoint, and migrate the plugin in `tests/bootstrap.php` too.
4. **Access.** In `config/app.php`, set who may open the settings page:
   `'RockSoftwareMessagingClient' => ['settingsAccess' => fn($identity, $request): bool => …]`.
5. **`App.fullBaseUrl`.** Make sure it is set to the application's own address in **both the web
   container and the worker container**, for example from the same `APP_FULL_BASE_URL`
   environment variable. Each sending switch is bound to this address (see below). The worker
   does the retries, and it has no request to work the address out from. A worker with another
   address, or none, reads every channel as off: its pending messages end
   `NOT_SENT_DELIVERY_OFF`, and `bin/cake messaging_client deliver` logs a warning line saying so.

Then do everything else on `/messaging-client/settings`:

1. Enter the gateway's address and the API token the gateway console issued for the application.
2. Test the connection. This also fetches the senders.
3. Enable senders and choose each channel's default.
4. Switch sending on for each channel.

### Settings

Every setting has a default in `MessagingClientPlugin::DEFAULTS`. Override them under
`RockSoftwareMessagingClient` in `config/app.php`; none of them is a secret, and none of them
configures messaging itself.

| Setting | Default | |
|---|---|---|
| `settingsAccess` | `null` | Who may open the settings page: `fn($identity, $request): bool`. Null means anyone signed in. It is asked after the application's own authentication, which sends a signed-out visitor to sign in as on any other page. Nobody who is not signed in ever gets in |
| `locale` | `null` | The language of the plugin's texts when it differs from the application's |
| `breadcrumbParent` | `null` | For an application whose pages write their breadcrumb in the template: the page above the settings page, a title or `['title' => …, 'url' => …]`. The page then shows "parent › Messaging" |
| `isTestMessageEnabled` | `true` | Whether the settings page offers a test message |
| `testRecipient` | `null` | The address the test message is offered to: `fn($identity): ?string`. Null reads the identity's `email` |
| `defaultCountryCode` | `'+358'` | The country code an SMS number written without one gets |
| `healthFailureThreshold` | `5` | How many failed messages in the last hour make the health endpoint report `recent_failures` |
| `isDeliveryEnabled` | `null` | An override that can only force sending **off**: `false`, or a callable that receives the channel and returns `false`. It never turns a channel on |
| `retryDelays` | `[60, 300, 900, 1800, 3600, 7200, 14400]` | Seconds before each further attempt of a message that met a temporary error. When they run out (about 7½ hours), the message ends `FAILED` |
| `deliverBatchSize` | `50` | How many waiting messages one `deliver` run sends |
| `latestMessagesLimit` | `20` | How many outbound messages the settings page lists |
| `httpClient` | `null` | The `Cake\Http\Client` the gateway client uses. Tests put a `FakeGateway` here |

## Sending

```php
use RockSoftware\MessagingClient\Messenger;
use RockSoftware\MessagingClient\SendRequest;

$message = (new Messenger())->send(SendRequest::email(
    to: $approver->email,
    externalRef: 'change-request-' . $changeRequest->id . '-approver-' . $approver->id,
    subject: __('A change request waits for you'),
    text: $text,
    context: ['change_request_id' => $changeRequest->id],
));

$message->statusValue(); // OutboundMessageStatusType::ACCEPTED, PENDING, FAILED, …
```

`SendRequest::sms(to:, externalRef:, text:, …)` sends an SMS. Its number goes out in E.164: `PhoneNumber`
turns `040 123 4567`, `+358 (0)40 123 4567` or `00358 40 123 4567` into `+358401234567`, using
`defaultCountryCode` for a number without a country code. Anything that cannot be a phone number
is refused before it is stored. Every other field the plugin
supports is a named argument: `sender`, `cc`, `bcc`, `replyTo`, `fromName`, `html`, `priority`
(`SendRequest::PRIORITY_TRANSACTIONAL` / `PRIORITY_BULK`), `context`, `isAutoSubmitted`, and
`validFor` or `expiresAt`.

The request is checked against the gateway's rules when it is built. A message the gateway would
refuse with `422` throws `InvalidArgumentException` before anything is stored, and so does a
`sender` the application may not use.

- **`externalRef` is required, and it makes a send happen once.** Build it from what the message
  is about, never from the time or a random value. Sending the same `externalRef` again returns
  the row already recorded and sends nothing; the gateway enforces the same.
- **`send()` never throws for a delivery problem.** The returned row says what happened:
  - `PENDING`: it will be tried again;
  - `ACCEPTED`: queued at the gateway;
  - `SUPPRESSED`: the recipient is on the gateway's block list;
  - `FAILED`: refused, expired or given up; `error` says why;
  - `NOT_SENT_DELIVERY_OFF`: sending is off for its channel.
- **Senders.** The senders are the gateway connections the application may send as, fetched from
  the gateway on the settings page. Each sender is enabled or not, and each channel has at most
  one default. Who a message goes out as:
  - the sender named in `sender:`, which must be an enabled sender of that channel;
  - otherwise the channel's default sender;
  - otherwise the gateway's own default for the application.

  The log keeps the sender used in `sender_code`. A sender the gateway stops listing is kept and
  shown as unavailable, never silently removed.

### What became of a message

The worker (`bin/cake messaging_client deliver`) also asks the gateway about every message it
accepted (`GET /messages/{uuid}`; the token needs `message.read`). It records the answer in the
gateway's own words:

- `SENT`: the provider took it. This is final for an email, since email never reports delivery,
  only a later bounce.
- `DELIVERED`, with `delivered_at`.
- `TRAPPED`: a test token or test connection; it reached no one.
- `CANCELLED`.
- `FAILED`, with the gateway's reason in `error` (`bounced: …`, `expired`, `21610: …`).

It asks often at first and less often as the message ages, and stops at a final status or after
72 hours. Each change fires `RockSoftwareMessagingClient.statusChanged` (`outboundMessage`,
`previousStatus`, `status`).

### Reading and resending the application's own messages

```php
/** @var \RockSoftware\MessagingClient\Model\Table\OutboundMessagesTable $messages */
$messages = $this->fetchTable('RockSoftware/MessagingClient.OutboundMessages');

$messages->find('withContext', key: 'change_request_id', value: 17);    // a top-level context value
$messages->find('withExternalRefPrefix', prefix: 'change-request-17-'); // wildcards taken literally
$messages->findByExternalRef('change-request-17-approver-4');

$new = (new Messenger())->resend($message, 'change-request-17-approver-4-again');
```

- **`withContext` matches the value with its type.** `17` is not `'17'`.
- **`resend()` sends a message that is no longer pending as a new outbound message**, under the
  new `external_ref`. It has the same channel, recipients, sender and content, and no deadline.
  The old row is never changed; the new one points to it (`resent_from_id`).

### Sending switches

Each channel (email, SMS) has its own sending switch on the settings page, and every channel
starts off. Messages recorded while a channel was off stay `NOT_SENT_DELIVERY_OFF`: switching it on
never sends them afterwards. Switching one on records the application's own address (`App.fullBaseUrl`), who
switched it and when.

A channel counts as on only when its switch is on **and** was switched on at the address the
application has now. A production database restored onto a development or preview host therefore
reads as off on every channel, until someone on that host switches a channel on there.

- **When a channel is off:** a message on it is recorded as `NOT_SENT_DELIVERY_OFF` and the
  gateway is never called. The worker turns any pending message on that channel into
  `NOT_SENT_DELIVERY_OFF` instead of sending it.
- **Who may switch:** only people `settingsAccess` lets in. Every switch fires
  `RockSoftwareMessagingClient.sendingSwitched` for the application's audit log.

The switches are this application's layer. A development or preview application should still
point at the development gateway or use a test token as a second layer: nothing reaches a person
unless this application's switch is on **and** the gateway's own switch is on **and** the token
is not a test token. The settings page shows all three.

### Retries and the worker

A send stays `PENDING` when:

- its outcome is unknown (no answer, or a `5xx`); or
- it met a temporary error: a refused token, a redirect, `429`, or the gateway not set up yet.

The application runs this from its worker loop, every minute or so:

```bash
bin/cake messaging_client deliver
```

`--limit N` caps one run. The command:

- sends the due rows again under the same `external_ref`, which is safe by design, waiting
  `retryDelays` between attempts;
- never lets two workers send the same row at once: a row is claimed before it is sent;
- needs no queue library.

Other outcomes are final:

- A refusal (`403`, `404`, `422`) ends the message `FAILED` at once, with the gateway's message.
- A message past its `validFor` / `expiresAt` ends `FAILED` without being sent.

## Health endpoint

`GET /messaging-client/health` answers JSON for a monitor. It needs no sign-in and holds no
personal data:

```json
{"status": "degraded", "problems": ["no_channel_enabled"], "time": "2026-10-24T09:00:00+00:00"}
```

| Problem | Meaning |
|---|---|
| `gateway_not_configured` | No gateway address or token, or the token cannot be read |
| `no_channel_enabled` | No channel's sending switch is on at this address |
| `messages_stuck_pending` | A message has waited an hour or more for the gateway to accept it |
| `recent_failures` | `healthFailureThreshold` or more messages failed in the last hour |

It always answers HTTP 200; add `?strict=1` to get HTTP 503 when it is `degraded`. The endpoint
does not extend the application's `AppController`. An application that requires sign-in
everywhere lists `/messaging-client/health` as a public path.

**Uptime Kuma:** add a monitor of type *HTTP(s) - Keyword*:

- URL: `https://<app>/messaging-client/health?strict=1`;
- keyword: `"status":"ok"`;
- heartbeat interval: 5 minutes.

The 503 or the missing keyword then raises the alert, and the response body says which problem
it is. On a development copy, where every channel is off by design, monitor
`https://<app>/messaging-client/health` without `strict` instead, or not at all.

## The settings page

The page has a status pill row and three tabs, deep-linked with `?tab=`; every action returns to
its tab:

- **Kanavat ja lähettäjät** (`channels`, the default): each channel's sending switch, its senders,
  and refreshing the senders from the gateway.
- **Yhteys** (`connection`): the gateway's address and token, the connection test and its last
  result, and the test message. The test message names the application by the settings plugin's
  `application.name`.
- **Lähetetyt viestit** (`messages`): the latest outbound messages.

On a phone the tables become cards.

**Breadcrumb.** The page extends the application's `AppController`, so the application's own
breadcrumb runs for it:

- **An application whose layout builds the breadcrumb**: list the page in the application's own
  menu, as any plugin page: `['plugin' => 'RockSoftware/MessagingClient', 'controller' => 'Settings', 'action' => 'index']`.
  The page also sets `breadcrumbTitle` ("Messaging", translated) and the page title.
- **An application whose pages write their breadcrumb in the template**: set
  `breadcrumbParent`. The page then shows "parent › Messaging", unless the layout already set a
  `breadcrumb` view variable.

`GET /messaging-client/settings` renders in the application's own layout: its controller extends
the application's `App\Controller\AppController`.

| Route | |
|---|---|
| `GET /messaging-client/settings` | The page |
| `POST …/switch-sending` | `channel`, `is_sending_enabled` |
| `POST …/update` | `gateway_url`, `api_token`, `remove_api_token`. The token is write-only, encrypted with a key derived from `Security.salt` |
| `POST …/test` | Checks the connection and refreshes the senders. Sends nothing |
| `POST …/refresh-senders` | Refreshes the senders from the gateway's `GET /connections` |
| `POST …/senders` | `enabled[]` (sender codes), `default[<channel>]` (a code, or empty for the gateway's default) |
| `POST …/send-test` | `recipient`, and optionally `channel` and `sender`. Without a channel, an email address gets an email and anything else an SMS |

Events on the global event manager, for the application's audit log. Each carries `identity`:

| Event | Data |
|---|---|
| `RockSoftwareMessagingClient.settingsChanged` | `fields`: names only, never the token |
| `RockSoftwareMessagingClient.connectionChecked` | `check` |
| `RockSoftwareMessagingClient.sendersChanged` | `codes` |
| `RockSoftwareMessagingClient.sendingSwitched` | `actor`, `channel`, `isSendingEnabled` |
| `RockSoftwareMessagingClient.testMessageSent` | `outboundMessage` |

### Moving to 1.1

The test message names the application by the settings plugin's `application.name`.
`RockSoftwareMessagingClient.appName` is no longer read: remove it, load and migrate the settings
plugin, and seed the name in a migration of the application's own with
`Settings::set('application.name', 'Example App')`.

### Moving an application's existing configuration over

An application's own data migration copies its settings into
`rock_software_messaging_client_settings`:

- the gateway's address into `gateway_url`;
- the token into `api_token`, encrypted with `RockSoftware\MessagingClient\Service\SecretCipher`;
- the sender it used for each channel into `email_account` / `sms_account`.

The plugin makes that sender the channel's default and empties the column. This happens in its
migration, and again on the next send or page view if the application's migration ran later.

## What is stored

- `rock_software_messaging_client_settings`: one row, with the token encrypted and the latest
  connection test's result.
- `rock_software_messaging_client_senders`: the gateway connections, one row per code.
- `rock_software_messaging_client_channel_settings`: the sending switches, at most one row per
  channel. No row means off.
- `rock_software_messaging_client_outbound_messages`: one row per `external_ref`. It holds the
  channel, recipient, subject, sender code, the gateway's message uuid, status, error, attempts,
  context and times, and the request as sent (`payload`), which `resend()` uses. The application
  decides how long to keep the rows.
- Type tables `rock_software_messaging_client_channel_types` (`email`, `sms`) and
  `rock_software_messaging_client_outbound_message_status_types`. Their rows are system rows from
  the migration. Adding a channel means a plugin migration that adds a type row. A gateway
  connection on a channel the plugin does not know is left out, with a log line.

The token is never logged, never rendered back, and never sent anywhere but the configured
https host: the client refuses any other address and never follows a redirect.

## Testing an application that sends

```php
use RockSoftware\MessagingClient\TestSuite\Fixture\MessagingClientFixtures;
use RockSoftware\MessagingClient\TestSuite\MessagingTestTrait;

class ChangeRequestsControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use MessagingTestTrait;

    protected array $fixtures = [...MessagingClientFixtures::ALL, 'app.Users'];

    public function testSubmittingTellsTheApprovers(): void
    {
        $this->useFakeGateway();

        $this->post('/change-requests/17/submit');

        $this->assertMessageSent(['channel' => 'email', 'to' => 'approver@example.test']);
    }
}
```

`useFakeGateway()` sets the plugin up the way a person would on the settings page: the gateway's
address and a token are stored, and every channel is switched on at `App.fullBaseUrl`. It then
points every gateway call at a `TestSuite\FakeGateway`, an HTTP client that never reaches the
network and answers the way the gateway does:

- queued, for a new message;
- `already_accepted`, for a known `external_ref`;
- `409`, for an address in `suppressedIdentities`.

Use `useFakeGateway(isDeliveryEnabled: false)` for switches that are off. Queue other answers
with `refuse(422, '…')`, `answer($status, $body)` or `failWith($exception)`.
`report($externalRef, 'delivered')` (or `'failed', 'bounced', '…'`) moves an accepted message on,
for the status checks.

**Fixtures.** The plugin must be loaded in the application's test bootstrap, as the
application's `bootstrap()` does (`addPlugin`), and the bootstrap migrates the plugin
(`(new Migrator())->runMany([[], ['plugin' => 'RockSoftware/MessagingClient']])`) and then empties
every table, so the type tables' system rows have to come back from fixtures. The plugin ships
them, autoloaded, under `RockSoftware\MessagingClient\TestSuite\Fixture`.
`MessagingClientFixtures::ALL` lists:

- `ChannelTypesFixture` and `OutboundMessageStatusTypesFixture`: exactly the rows the migration
  seeds, a fresh installation's and nothing more. A test in this repository keeps them equal to
  the migration.
- `SettingsFixture`, `ChannelSettingsFixture`, `SendersFixture` and `OutboundMessagesFixture`:
  empty.

Give fixtures by class name: `plugin.…` fixture names resolve to a plugin's `tests/` folder, which
an application cannot load. Each fixture names the plugin's own Table class
(`$tableAlias = 'RockSoftware/MessagingClient.…'`), so the fixtures load in an application that
switches CakePHP's fallback Table classes off.

## Developing the plugin

```bash
composer install
vendor/bin/phpunit
composer cs-check
```

Tests run on SQLite and never reach a gateway. CI runs them on PHP 8.3 and 8.4.

## License

Proprietary.
