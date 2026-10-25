# Contributor guide — rocksoftware-cakephp-messaging-client

A **CakePHP 5 plugin** (Composer package `makallio85/rocksoftware-cakephp-messaging-client`;
CakePHP plugin `RockSoftware/MessagingClient`) through which an application sends email and SMS
via the messaging gateway's public API (`/public-api/v1`). See `README.md` for what it does.

## Quick start

```bash
composer install
vendor/bin/phpunit
composer cs-check
```

## Rules

1. **Pull requests target `dev`**, never `master`.
2. **Nothing reaches a person by accident.** Every send goes through `Messenger`, which asks
   `Service\DeliveryGate::isEnabledFor($channel)` before each attempt, the worker's included: the
   channel's sending switch must be on **and** bound to this `App.fullBaseUrl` (a restored
   database on another host reads as off). Configuration can force a channel off, never on.
   Never add a path to the gateway's `POST /messages` that skips it. Tests never reach a real
   gateway: they use `TestSuite\FakeGateway` (`MessagingTestTrait::useFakeGateway()`).
3. **Send once.** `external_ref` is required and unique (`rock_software_messaging_client_outbound_messages`);
   a retry always reuses it. A temporary error or an unknown outcome leaves a row `PENDING` for
   `bin/cake messaging_client deliver`; only `403`/`404`/`422` (`GatewayException::isTemporary`
   false) end it `FAILED` at once. Add a test for every new outcome.
4. **The token.** Only in the `Authorization` header, only to the https host
   `GatewayClient::apiRoot()` accepts, redirects never followed, never logged or rendered
   (`#[SensitiveParameter]`, `__debugInfo()`). Stored encrypted by `Service\SecretCipher`.
5. **The plugin owns all messaging configuration.** The gateway connection, the senders
   (`*_senders`, at most one default per channel, kept by `SendersTable`) and the sending switches
   (`*_channel_settings`) live on the plugin's settings page; an application only sends through
   `Messenger`. Installing stays require, `addPlugin`, migrate and `settingsAccess`; every setting
   has a default in `MessagingClientPlugin::DEFAULTS`. The application's audit log is reached
   through the settings page's events. `CredentialsProviderInterface` exists only so tests can
   pass `StaticCredentialsProvider`.
6. **Fixed sets are type tables** (`*_channel_types`, `*_outbound_message_status_types`), seeded
   by the migration and bound to by value through the entity's constants; a new channel is a
   migration adding a type row. Yes/no names start with `is_`/`has_`. The term for a sent
   message is **outbound message**.
7. **Texts are translatable.** Every user-facing string goes through
   `__d('rock_software_messaging_client', …)` and has a Finnish entry in
   `resources/locales/fi/rock_software_messaging_client.po` in the same change
   (`TranslationsTest` fails otherwise). Developer-facing exceptions (`SendRequest` validation)
   stay English.
8. **Tests** run on SQLite. The bootstrap migrates and then empties every table, as an
   application's does. Fixtures ship in `src/TestSuite/Fixture` (applications load them; list
   them in `MessagingClientFixtures::ALL`) and hold a fresh installation's rows only: the type
   tables' migration rows (`TypeFixturesTest` keeps them equal to the migration; change both
   together) and nothing else. Each test creates what it needs. Test data uses
   `*.example.test` / `example.com` addresses and fictional phone numbers only.
9. Do not commit `vendor/`, `composer.lock`, `tmp/` or `.phpunit.cache/`.
