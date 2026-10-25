<?php
/**
 * Settings › Messaging: status pills and three tabs (`?tab=`), rendered in
 * the application's layout with the page's own styles, scoped under `.rsmc`.
 *
 * @var \Cake\View\View $this
 * @var string $tab
 * @var array{title: string, url?: array<string, mixed>|string|null}|string|null $breadcrumbParent
 * @var \RockSoftware\MessagingClient\Model\Entity\Setting $setting
 * @var bool $isConfigured
 * @var array<string, \RockSoftware\MessagingClient\Service\ChannelSwitch> $sendingSwitches
 * @var string|null $appBaseUrl
 * @var bool $isTestMessageEnabled
 * @var \RockSoftware\MessagingClient\Service\ConnectionCheck|null $connectionCheck
 * @var array<string, list<\RockSoftware\MessagingClient\Model\Entity\Sender>> $senders
 * @var string|null $testRecipient
 * @var list<\RockSoftware\MessagingClient\Model\Entity\OutboundMessage> $outboundMessages
 */

use RockSoftware\MessagingClient\Model\Entity\ChannelType;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessageStatusType;

$this->assign('title', __d('rock_software_messaging_client', 'Messaging'));

$url = fn(string $action, array $query = []): string => $this->Url->build(
    ['plugin' => 'RockSoftware/MessagingClient', 'controller' => 'Settings', 'action' => $action, '?' => $query],
);
$time = fn($dateTime): string => $dateTime === null ? '' : $dateTime->i18nFormat('d.M. HH:mm');
$host = $appBaseUrl === null ? null : (string)parse_url($appBaseUrl, PHP_URL_HOST);
$channelName = fn(string $channel): string => match ($channel) {
    ChannelType::EMAIL => __d('rock_software_messaging_client', 'Email'),
    ChannelType::SMS => __d('rock_software_messaging_client', 'Text messages'),
    default => $channel,
};

// The connection's pill, from the latest "Test connection".
$connectionPill = match (true) {
    !$isConfigured => ['muted', __d('rock_software_messaging_client', 'Connection not set up')],
    $setting->checked_at === null => ['muted', __d('rock_software_messaging_client', 'Connection not tested')],
    $setting->is_connection_ok !== true => ['bad', __d('rock_software_messaging_client', 'Connection not working')],
    $setting->is_test_token === true => ['warn', __d('rock_software_messaging_client', 'Connection works: test token')],
    $setting->is_gateway_sending_enabled === false => [
        'warn',
        __d('rock_software_messaging_client', 'Connection works: gateway sending off'),
    ],
    default => ['ok', __d('rock_software_messaging_client', 'Connection to the gateway OK')],
};

/**
 * A sender's state at the gateway: [css modifier, text].
 *
 * @param \RockSoftware\MessagingClient\Model\Entity\Sender $sender The sender.
 * @return array{0: string, 1: string}
 */
$senderState = fn($sender): array => match (true) {
    !$sender->is_available => ['bad', __d('rock_software_messaging_client', 'No longer at the gateway')],
    !$sender->is_outbound_enabled => ['warn', __d('rock_software_messaging_client', 'Sending off at the gateway')],
    $sender->is_test => ['warn', __d('rock_software_messaging_client', 'Test mode: reaches no one')],
    !$sender->is_owned => ['muted', __d('rock_software_messaging_client', 'Shared by another application')],
    default => ['ok', __d('rock_software_messaging_client', 'Live')],
};

$sendersByCode = [];
foreach ($senders as $channelSenders) {
    foreach ($channelSenders as $sender) {
        $sendersByCode[$sender->code] = $sender;
    }
}

/**
 * The badge of an outbound message: [css modifier, text].
 *
 * @param \RockSoftware\MessagingClient\Model\Entity\OutboundMessage $message The message.
 * @return array{0: string, 1: string}
 */
$badge = function ($message): array {
    $value = (string)$message->statusValue();

    return match ($value) {
        OutboundMessageStatusType::ACCEPTED, OutboundMessageStatusType::SENT => $message->is_test
            ? ['warn', __d('rock_software_messaging_client', 'Accepted, trapped as a test')]
            : ['ok', OutboundMessageStatusType::label($value)],
        OutboundMessageStatusType::DELIVERED => ['ok', OutboundMessageStatusType::label($value)],
        OutboundMessageStatusType::PENDING => $message->attempts > 0
            ? ['warn', __d('rock_software_messaging_client', 'Waiting for another attempt ({0})', $message->attempts)]
            : ['warn', OutboundMessageStatusType::label($value)],
        OutboundMessageStatusType::TRAPPED => ['warn', OutboundMessageStatusType::label($value)],
        OutboundMessageStatusType::FAILED, OutboundMessageStatusType::SUPPRESSED => [
            'bad',
            OutboundMessageStatusType::label($value),
        ],
        default => ['muted', OutboundMessageStatusType::label($value)],
    };
};
$hasError = fn($message): bool => $message->error !== null && in_array(
    $message->statusValue(),
    [
        OutboundMessageStatusType::PENDING,
        OutboundMessageStatusType::FAILED,
        OutboundMessageStatusType::SUPPRESSED,
        OutboundMessageStatusType::NOT_SENT_DELIVERY_OFF,
    ],
    true,
);
$tabs = [
    'channels' => __d('rock_software_messaging_client', 'Channels and senders'),
    'connection' => __d('rock_software_messaging_client', 'Connection'),
    'messages' => __d('rock_software_messaging_client', 'Sent messages'),
];
?>
<style>
.rsmc{--rsmc-border:#dee2e6;--rsmc-muted:#5c636a;--rsmc-accent:#4f46e5;color:#212529;font-size:14px;display:flex;flex-direction:column;gap:16px;max-width:1120px}
.rsmc h1{margin:0;font-size:22px;font-weight:600}
.rsmc-breadcrumb{display:flex;gap:6px;flex-wrap:wrap;font-size:13px;color:var(--rsmc-muted);margin-bottom:-8px}
.rsmc-breadcrumb a{color:inherit}
.rsmc-pills{display:flex;gap:8px;flex-wrap:wrap;font-size:13px}
.rsmc-pill,.rsmc-badge{display:inline-block;border-radius:999px;padding:3px 10px;white-space:nowrap;font-size:13px;line-height:1.4}
.rsmc-badge{font-size:12px;padding:2px 8px}
.rsmc-ok{background:#d1e7dd;color:#0f5132}
.rsmc-warn{background:#fff3cd;color:#664d03}
.rsmc-bad{background:#f8d7da;color:#842029}
.rsmc-muted{background:#e9ecef;color:#495057}
.rsmc-tabs{display:flex;gap:4px;border-bottom:1px solid var(--rsmc-border);margin:0;padding:0;list-style:none;overflow-x:auto}
.rsmc-tabs a{display:block;border:1px solid transparent;border-bottom:0;border-radius:6px 6px 0 0;padding:9px 16px;font-size:14px;font-weight:600;color:var(--rsmc-muted);text-decoration:none;white-space:nowrap;margin-bottom:-1px}
.rsmc-tabs a[aria-current=page]{background:#fff;color:#212529;border-color:var(--rsmc-border)}
.rsmc-panel{background:#fff;border:1px solid var(--rsmc-border);border-radius:8px}
.rsmc-panel>h2{margin:0;padding:10px 16px;border-bottom:1px solid var(--rsmc-border);font-size:14px;font-weight:600}
.rsmc-stack{display:flex;flex-direction:column;gap:16px}
.rsmc-body{padding:16px;display:flex;flex-direction:column;gap:14px}
.rsmc-narrow{max-width:640px}
.rsmc-line{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;font-size:13px;color:var(--rsmc-muted)}
.rsmc-line form{margin:0}
.rsmc-channel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--rsmc-border);flex-wrap:wrap}
.rsmc-channel-head form{margin:0}
.rsmc-channel-name{display:flex;flex-direction:column;gap:2px}
.rsmc-channel-name strong{font-size:15px;font-weight:600}
.rsmc-small{font-size:12px;color:var(--rsmc-muted)}
.rsmc-switch{display:inline-flex;align-items:center;gap:8px;border:0;background:none;padding:4px 0;font-size:14px;font-weight:600;cursor:pointer;color:var(--rsmc-muted);min-height:40px}
.rsmc-switch[aria-checked=true]{color:#0f5132}
.rsmc-track{position:relative;width:40px;height:22px;border-radius:999px;background:#adb5bd;flex:none}
.rsmc-track::after{content:"";position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff}
.rsmc-switch[aria-checked=true] .rsmc-track{background:#198754}
.rsmc-switch[aria-checked=true] .rsmc-track::after{left:21px}
.rsmc-switch:focus-visible{outline:2px solid var(--rsmc-accent);outline-offset:2px;border-radius:4px}
.rsmc-note{padding:8px 16px;font-size:12px;color:var(--rsmc-muted);border-bottom:1px solid var(--rsmc-border)}
.rsmc-note-warn{background:#fff3cd;color:#664d03}
.rsmc-table{width:100%;border-collapse:collapse;font-size:13px}
.rsmc-table th{text-align:left;color:var(--rsmc-muted);padding:8px 16px;border-bottom:1px solid var(--rsmc-border);font-weight:600}
.rsmc-table td{padding:8px 16px;border-bottom:1px solid #f1f3f5;vertical-align:top}
.rsmc-table tr:last-child td{border-bottom:0}
.rsmc-senders{table-layout:fixed}
.rsmc-senders th:nth-child(2){width:20%}.rsmc-senders th:nth-child(3){width:15%}.rsmc-senders th:nth-child(4){width:21%}.rsmc-senders th:nth-child(5),.rsmc-senders th:nth-child(6){width:8%}
.rsmc-senders td{overflow-wrap:anywhere}
.rsmc-code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
.rsmc-nowrap{white-space:nowrap}
.rsmc-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--rsmc-border);font-size:13px}
.rsmc-foot label{display:flex;gap:6px;align-items:center}
.rsmc-field{display:flex;flex-direction:column;gap:4px}
.rsmc-field label,.rsmc-label{font-weight:600}
.rsmc-field .rsmc-check{font-weight:400;font-size:13px;display:flex;gap:6px;align-items:center}
.rsmc input[type=text],.rsmc input[type=password],.rsmc select{border:1px solid #ced4da;border-radius:6px;padding:8px 10px;font-size:14px;background:#fff;color:#212529;min-height:40px;box-sizing:border-box;width:100%}
.rsmc-help{font-size:12px;color:var(--rsmc-muted)}
.rsmc-buttons{display:flex;gap:8px;flex-wrap:wrap}
.rsmc-button{border:1px solid #6c757d;background:#fff;color:#495057;border-radius:6px;padding:8px 14px;font-size:14px;min-height:40px;cursor:pointer}
.rsmc-button-small{padding:6px 12px;font-size:13px;min-height:34px}
.rsmc-button-primary{border-color:var(--rsmc-accent);background:var(--rsmc-accent);color:#fff}
.rsmc-result{border-radius:6px;padding:8px 10px;font-size:13px}
.rsmc-result ul{margin:4px 0 0;padding-left:18px}
.rsmc-test{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:12px;align-items:end}
.rsmc-error{display:block;color:#842029;font-size:12px;margin-top:2px}
.rsmc-empty{padding:16px;color:var(--rsmc-muted);font-size:13px;margin:0}
@media (max-width:640px){
.rsmc{gap:12px;font-size:13px}
.rsmc h1{font-size:18px}
.rsmc-tabs a{padding:10px 12px;font-size:13px;min-height:44px;box-sizing:border-box}
.rsmc-channel-head{padding:12px}
.rsmc-switch{min-height:44px}
.rsmc-table thead{display:none}
.rsmc-table,.rsmc-table tbody,.rsmc-table tr,.rsmc-table td{display:block;width:auto}
.rsmc-table tr{padding:10px 12px;border-bottom:1px solid var(--rsmc-border)}
.rsmc-table tr:last-child{border-bottom:0}
.rsmc-table td{padding:2px 0;border:0;overflow-wrap:anywhere}
.rsmc-table td[data-label]::before{content:attr(data-label) ": ";color:var(--rsmc-muted)}
.rsmc-table td.rsmc-check-cell{display:flex;justify-content:space-between;align-items:center;min-height:44px}
.rsmc-table td.rsmc-check-cell::before{content:attr(data-label)}
.rsmc-table td.rsmc-check-cell input{width:22px;height:22px}
.rsmc-body{padding:12px}
.rsmc-test{grid-template-columns:1fr}
.rsmc-buttons{flex-direction:column}
.rsmc-button{min-height:44px;width:100%}
.rsmc input[type=text],.rsmc input[type=password],.rsmc select{min-height:44px}
}
</style>
<div class="rsmc">
    <?php
    // An application whose layout builds the breadcrumb sets `breadcrumb`; one whose
    // pages write theirs names the parent in `breadcrumbParent`.
    $parent = is_string($breadcrumbParent) ? ['title' => $breadcrumbParent] : $breadcrumbParent;
    ?>
    <?php if (is_array($parent) && isset($parent['title']) && $this->get('breadcrumb') === null) : ?>
        <nav aria-label="Breadcrumb" class="rsmc-breadcrumb">
            <?php if (!empty($parent['url'])) : ?>
                <?= $this->Html->link((string)$parent['title'], $parent['url']) ?>
            <?php else : ?>
                <span><?= h((string)$parent['title']) ?></span>
            <?php endif; ?>
            <span aria-hidden="true">›</span>
            <span aria-current="page"><?= h(__d('rock_software_messaging_client', 'Messaging')) ?></span>
        </nav>
    <?php endif; ?>
    <div class="rsmc-stack" style="gap:8px">
        <h1><?= h(__d('rock_software_messaging_client', 'Messaging')) ?></h1>
        <div class="rsmc-pills">
            <span class="rsmc-pill rsmc-<?= h($connectionPill[0]) ?>"><?= h($connectionPill[1]) ?></span>
            <?php foreach ($sendingSwitches as $channel => $switch) : ?>
                <?php [$modifier, $text] = match (true) {
                    $switch->isOn() => ['ok', __d('rock_software_messaging_client', '{0} on', $channelName($channel))],
                    $switch->isSwitchedOnElsewhere() => [
                        'warn',
                        __d('rock_software_messaging_client', '{0} on at another address', $channelName($channel)),
                    ],
                    default => ['muted', __d('rock_software_messaging_client', '{0} off', $channelName($channel))],
                }; ?>
                <span class="rsmc-pill rsmc-<?= h($modifier) ?>"><?= h($text) ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <nav aria-label="<?= h(__d('rock_software_messaging_client', 'Messaging settings')) ?>">
        <ul class="rsmc-tabs">
            <?php foreach ($tabs as $key => $label) : ?>
                <li><a href="<?= h($url('index', $key === 'channels' ? [] : ['tab' => $key])) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= h($label) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <?php if ($tab === 'channels') : ?>
    <div class="rsmc-stack">
        <div class="rsmc-line">
            <span><?= h($host === null
                ? __d('rock_software_messaging_client', 'Sending cannot be switched on: this application\'s address (App.fullBaseUrl) is not configured.')
                : __d('rock_software_messaging_client', 'Sending is on only at {0}. A copy of the database anywhere else is always off.', $host)) ?></span>
            <?= $this->Form->create(null, ['url' => $url('refreshSenders')]) ?>
                <button type="submit" class="rsmc-button rsmc-button-small"><?= h(__d('rock_software_messaging_client', 'Refresh the list from the gateway')) ?></button>
            <?= $this->Form->end() ?>
        </div>

        <?php foreach ($sendingSwitches as $channel => $switch) : ?>
            <?php $channelSenders = $senders[$channel] ?? []; ?>
            <section class="rsmc-panel" aria-labelledby="rsmc-channel-<?= h($channel) ?>">
                <div class="rsmc-channel-head">
                    <span class="rsmc-channel-name">
                        <strong id="rsmc-channel-<?= h($channel) ?>"><?= h($channelName($channel)) ?></strong>
                        <span class="rsmc-small"><?= h(match (true) {
                            $switch->changedAt === null => __d('rock_software_messaging_client', 'Never switched on'),
                            $switch->isSwitchedOn => __d(
                                'rock_software_messaging_client',
                                'Switched on by {0} {1}',
                                (string)$switch->changedBy,
                                $time($switch->changedAt),
                            ),
                            default => __d(
                                'rock_software_messaging_client',
                                'Switched off by {0} {1}',
                                (string)$switch->changedBy,
                                $time($switch->changedAt),
                            ),
                        }) ?></span>
                    </span>
                    <?= $this->Form->create(null, ['url' => $url('switchSending')]) ?>
                        <input type="hidden" name="channel" value="<?= h($channel) ?>">
                        <input type="hidden" name="is_sending_enabled" value="<?= $switch->isOn() ? '0' : '1' ?>">
                        <button type="submit" class="rsmc-switch" role="switch" aria-checked="<?= $switch->isOn() ? 'true' : 'false' ?>"
                            aria-label="<?= h(__d('rock_software_messaging_client', 'Sending {0}', $channelName($channel))) ?>">
                            <span><?= h($switch->isOn()
                                ? __d('rock_software_messaging_client', 'Sending on')
                                : __d('rock_software_messaging_client', 'Sending off')) ?></span>
                            <span class="rsmc-track" aria-hidden="true"></span>
                        </button>
                    <?= $this->Form->end() ?>
                </div>
                <?php if ($switch->isSwitchedOnElsewhere()) : ?>
                    <p class="rsmc-note rsmc-note-warn" style="margin:0"><?= h(__d(
                        'rock_software_messaging_client',
                        'Switched on at {0}, so it is off here. Switch it on here to send from this address.',
                        (string)$switch->enabledForUrl,
                    )) ?></p>
                <?php endif; ?>
                <p class="rsmc-note" style="margin:0"><?= h(__d(
                    'rock_software_messaging_client',
                    'Messages recorded while sending is off are not sent afterwards.',
                )) ?></p>

                <?php if ($channelSenders === []) : ?>
                    <p class="rsmc-empty"><?= h(__d(
                        'rock_software_messaging_client',
                        'No senders yet. Test the connection or refresh the list from the gateway; until then the gateway\'s default is used.',
                    )) ?></p>
                <?php else : ?>
                    <?= $this->Form->create(null, ['url' => $url('senders')]) ?>
                        <input type="hidden" name="channel" value="<?= h($channel) ?>">
                        <table class="rsmc-table rsmc-senders">
                            <thead><tr>
                                <th scope="col" style="width:28%"><?= h(__d('rock_software_messaging_client', 'Sender')) ?></th>
                                <th scope="col"><?= h(__d('rock_software_messaging_client', 'Display name')) ?></th>
                                <th scope="col"><?= h(__d('rock_software_messaging_client', 'Code')) ?></th>
                                <th scope="col"><?= h(__d('rock_software_messaging_client', 'At the gateway')) ?></th>
                                <th scope="col"><?= h(__d('rock_software_messaging_client', 'Default')) ?></th>
                                <th scope="col"><?= h(__d('rock_software_messaging_client', 'In use')) ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($channelSenders as $sender) : ?>
                                <?php
                                [$modifier, $text] = $senderState($sender);
                                $identity = $sender->identity ?? $sender->code;
                                ?>
                                <tr>
                                    <td><strong style="font-weight:600"><?= h($identity) ?></strong></td>
                                    <td data-label="<?= h(__d('rock_software_messaging_client', 'Display name')) ?>"><?= h((string)$sender->display_name) ?></td>
                                    <td data-label="<?= h(__d('rock_software_messaging_client', 'Code')) ?>"><span class="rsmc-code"><?= h($sender->code) ?></span></td>
                                    <td><span class="rsmc-badge rsmc-<?= h($modifier) ?>"><?= h($text) ?></span></td>
                                    <td class="rsmc-check-cell" data-label="<?= h(__d('rock_software_messaging_client', 'Default')) ?>">
                                        <input type="radio" name="default[<?= h($channel) ?>]" value="<?= h($sender->code) ?>"<?= $sender->is_default ? ' checked' : '' ?>
                                            aria-label="<?= h(__d('rock_software_messaging_client', 'Default: {0}', $identity)) ?>">
                                    </td>
                                    <td class="rsmc-check-cell" data-label="<?= h(__d('rock_software_messaging_client', 'In use')) ?>">
                                        <input type="checkbox" name="enabled[]" value="<?= h($sender->code) ?>"<?= $sender->is_enabled ? ' checked' : '' ?>
                                            aria-label="<?= h(__d('rock_software_messaging_client', '{0} in use', $identity)) ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="rsmc-foot">
                            <label><input type="radio" name="default[<?= h($channel) ?>]" value=""<?= array_filter($channelSenders, fn($s) => $s->is_default) === [] ? ' checked' : '' ?>>
                                <?= h(__d('rock_software_messaging_client', 'No default: the gateway\'s default for this application')) ?></label>
                            <button type="submit" class="rsmc-button rsmc-button-small"><?= h(__d('rock_software_messaging_client', 'Save senders')) ?></button>
                        </div>
                    <?= $this->Form->end() ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($tab === 'connection') : ?>
    <div class="rsmc-stack">
        <section class="rsmc-panel" aria-label="<?= h(__d('rock_software_messaging_client', 'Connection')) ?>">
            <?= $this->Form->create(null, ['url' => $url('update'), 'class' => 'rsmc-body rsmc-narrow']) ?>
                <div class="rsmc-field">
                    <label for="rsmc-gateway-url"><?= h(__d('rock_software_messaging_client', 'Gateway address')) ?></label>
                    <input type="text" id="rsmc-gateway-url" name="gateway_url" inputmode="url" autocomplete="off"
                        value="<?= h((string)$setting->gateway_url) ?>" placeholder="https://messaging.example.com">
                    <span class="rsmc-help"><?= h(__d('rock_software_messaging_client', 'https:// and the host name, no path.')) ?></span>
                </div>
                <div class="rsmc-field">
                    <label for="rsmc-api-token"><?= h(__d('rock_software_messaging_client', 'API token')) ?></label>
                    <input type="password" id="rsmc-api-token" name="api_token" autocomplete="new-password" value=""
                        placeholder="<?= h($setting->hasToken()
                            ? __d('rock_software_messaging_client', 'Stored · type a new one to replace it')
                            : __d('rock_software_messaging_client', 'Not stored yet')) ?>">
                    <?php if ($setting->hasToken()) : ?>
                        <label class="rsmc-check"><input type="checkbox" name="remove_api_token" value="1">
                            <?= h(__d('rock_software_messaging_client', 'Remove the stored token')) ?></label>
                    <?php else : ?>
                        <span class="rsmc-help"><?= h(__d(
                            'rock_software_messaging_client',
                            'Issued for this application in the gateway console, with the permissions system.status.read, message.send and message.read.',
                        )) ?></span>
                    <?php endif; ?>
                </div>
                <div class="rsmc-buttons">
                    <button type="submit" class="rsmc-button rsmc-button-primary"><?= h(__d('rock_software_messaging_client', 'Save')) ?></button>
                    <button type="submit" class="rsmc-button" formaction="<?= h($url('test')) ?>"><?= h(__d('rock_software_messaging_client', 'Test connection')) ?></button>
                </div>

                <?php if ($connectionCheck !== null && $connectionCheck->error !== null) : ?>
                    <div class="rsmc-result rsmc-bad" role="status"><?= h($connectionCheck->error) ?></div>
                <?php elseif ($setting->checked_at !== null && $setting->is_connection_ok !== true) : ?>
                    <div class="rsmc-result rsmc-bad" role="status"><?= h(__d(
                        'rock_software_messaging_client',
                        'Tested {0}: {1}',
                        $time($setting->checked_at),
                        (string)($setting->check_error ?? __d('rock_software_messaging_client', 'The token may not send.')),
                    )) ?></div>
                <?php elseif ($setting->checked_at !== null) : ?>
                    <?php $isFine = $setting->is_test_token !== true && $setting->is_gateway_sending_enabled !== false; ?>
                    <div class="rsmc-result rsmc-<?= $isFine ? 'ok' : 'warn' ?>" role="status">
                        <?= h(__d(
                            'rock_software_messaging_client',
                            'The gateway answered {0}. The token {1} is valid and may send.',
                            $time($setting->checked_at),
                            (string)$setting->token_name,
                        )) ?>
                        <?= h($setting->is_gateway_sending_enabled === false
                            ? __d('rock_software_messaging_client', 'The gateway\'s own sending switch is off: nothing sent through it reaches anyone.')
                            : __d('rock_software_messaging_client', 'The gateway\'s own sending switch is on.')) ?>
                        <?php if ($setting->is_test_token === true) : ?>
                            <?= h(__d('rock_software_messaging_client', 'It is a test token: the gateway traps everything sent with it.')) ?>
                        <?php endif; ?>
                        <?php if ($connectionCheck !== null && $connectionCheck->missingPermissions() !== []) : ?>
                            <ul><li><?= h(__d('rock_software_messaging_client', 'The token lacks the permission {0}.', implode(', ', $connectionCheck->missingPermissions()))) ?></li></ul>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?= $this->Form->end() ?>
        </section>

        <?php if ($isTestMessageEnabled) : ?>
        <section class="rsmc-panel" aria-labelledby="rsmc-test-heading">
            <h2 id="rsmc-test-heading"><?= h(__d('rock_software_messaging_client', 'Test message')) ?></h2>
            <?= $this->Form->create(null, ['url' => $url('sendTest'), 'class' => 'rsmc-body']) ?>
                <div class="rsmc-test">
                    <div class="rsmc-field">
                        <label for="rsmc-test-channel"><?= h(__d('rock_software_messaging_client', 'Channel')) ?></label>
                        <select id="rsmc-test-channel" name="channel">
                            <?php foreach (ChannelType::VALUES as $channel) : ?>
                                <option value="<?= h($channel) ?>"><?= h($channelName($channel)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rsmc-field">
                        <label for="rsmc-test-sender"><?= h(__d('rock_software_messaging_client', 'Sender')) ?></label>
                        <select id="rsmc-test-sender" name="sender">
                            <option value=""><?= h(__d('rock_software_messaging_client', 'The channel\'s default')) ?></option>
                            <?php foreach ($senders as $channel => $channelSenders) : ?>
                                <optgroup label="<?= h($channelName($channel)) ?>">
                                    <?php foreach ($channelSenders as $sender) : ?>
                                        <?php if ($sender->isUsable()) : ?>
                                            <option value="<?= h($sender->code) ?>"><?= h(($sender->identity ?? $sender->code) . ($sender->is_default ? ' · ' . __d('rock_software_messaging_client', 'default') : '')) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rsmc-field">
                        <label for="rsmc-recipient"><?= h(__d('rock_software_messaging_client', 'Recipient')) ?></label>
                        <input type="text" id="rsmc-recipient" name="recipient" autocomplete="off" value="<?= h((string)$testRecipient) ?>">
                    </div>
                    <button type="submit" class="rsmc-button"><?= h(__d('rock_software_messaging_client', 'Send a test message')) ?></button>
                </div>
                <span class="rsmc-help"><?= h(__d(
                    'rock_software_messaging_client',
                    'The test message goes out only when the chosen channel\'s sending is on, on the Channels and senders tab.',
                )) ?></span>
            <?= $this->Form->end() ?>
        </section>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($tab === 'messages') : ?>
    <section class="rsmc-panel" aria-label="<?= h(__d('rock_software_messaging_client', 'Sent messages')) ?>">
        <?php if ($outboundMessages === []) : ?>
            <p class="rsmc-empty"><?= h(__d('rock_software_messaging_client', 'Nothing has been sent yet.')) ?></p>
        <?php else : ?>
            <div style="overflow-x:auto">
                <table class="rsmc-table">
                    <thead><tr>
                        <th scope="col"><?= h(__d('rock_software_messaging_client', 'Time')) ?></th>
                        <th scope="col"><?= h(__d('rock_software_messaging_client', 'Channel')) ?></th>
                        <th scope="col"><?= h(__d('rock_software_messaging_client', 'Sender')) ?></th>
                        <th scope="col"><?= h(__d('rock_software_messaging_client', 'Recipient')) ?></th>
                        <th scope="col"><?= h(__d('rock_software_messaging_client', 'Subject')) ?></th>
                        <th scope="col"><?= h(__d('rock_software_messaging_client', 'Status')) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($outboundMessages as $message) : ?>
                        <?php
                        [$modifier, $text] = $badge($message);
                        $senderName = $message->sender_code === null
                            ? __d('rock_software_messaging_client', 'The gateway\'s default')
                            : ($sendersByCode[$message->sender_code]->identity ?? $message->sender_code);
                        ?>
                        <tr>
                            <td class="rsmc-nowrap"><?= h($time($message->created)) ?></td>
                            <td data-label="<?= h(__d('rock_software_messaging_client', 'Channel')) ?>"><?= h(ChannelType::label((string)$message->channelValue())) ?></td>
                            <td data-label="<?= h(__d('rock_software_messaging_client', 'Sender')) ?>"><?= h($senderName) ?></td>
                            <td data-label="<?= h(__d('rock_software_messaging_client', 'Recipient')) ?>"><?= h($message->recipient) ?></td>
                            <td><?= h($message->subject ?? '–') ?></td>
                            <td>
                                <span class="rsmc-badge rsmc-<?= h($modifier) ?>"><?= h($text) ?></span>
                                <?php if ($hasError($message)) : ?>
                                    <span class="rsmc-error"><?= h((string)$message->error) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
