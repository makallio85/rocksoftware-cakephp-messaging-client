<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient;

use Cake\I18n\DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;

/**
 * One message for the gateway's `POST /messages`, checked against the
 * gateway's rules before anything is stored or sent: a request that would be
 * refused with `422` never reaches the delivery log.
 *
 * `externalRef` is required. It is the application's own id for this send,
 * unique within the application: sending the same one again returns the
 * message already recorded, here and at the gateway, and nothing reaches the
 * recipient twice. Build it from what the message is about
 * (`change-request-17-submitted-approver-4`), never from the time or a random
 * value, or a retry becomes a second message.
 *
 * ```php
 * $request = SendRequest::email(
 *     to: 'liisa@example.test',
 *     externalRef: 'change-request-17-approver-4',
 *     subject: 'A change request waits for you',
 *     text: 'Open it at https://app.example.test/change-requests/17',
 * );
 * ```
 */
final class SendRequest
{
    /** Someone is waiting on this one message (the default). */
    public const PRIORITY_TRANSACTIONAL = 'transactional';

    /** One of many: sent after transactional messages. */
    public const PRIORITY_BULK = 'bulk';

    /**
     * @var list<string>
     */
    public const PRIORITIES = [self::PRIORITY_TRANSACTIONAL, self::PRIORITY_BULK];

    /** The gateway's limit on `external_ref`. */
    public const MAX_EXTERNAL_REF_LENGTH = 191;

    /** The gateway's limit on an SMS, in characters. */
    public const MAX_SMS_LENGTH = 1600;

    /** The gateway's limit on `text` and on `html`, in bytes. */
    public const MAX_BODY_BYTES = 262144;

    /** The gateway's limit on the recipients of one email. */
    public const MAX_EMAIL_RECIPIENTS = 50;

    /** The gateway's limit on `valid_for`, in seconds (30 days). */
    public const MAX_VALID_FOR = 2592000;

    /**
     * @var list<string>
     */
    public readonly array $to;

    /**
     * @var list<string>
     */
    public readonly array $cc;

    /**
     * @var list<string>
     */
    public readonly array $bcc;

    /**
     * @var list<string>
     */
    public readonly array $replyTo;

    /**
     * @param string $channel `ChannelType::EMAIL` or `ChannelType::SMS`.
     * @param list<string>|string $to The recipient, written however the application has it; on email also a list,
     *   the first being the one the conversation is with. An SMS number becomes E.164 (`PhoneNumber`), or the
     *   request is refused.
     * @param string $externalRef The application's id for this send, 1 to 191 characters.
     * @param string|null $text Plain-text body.
     * @param string|null $html HTML body, email only.
     * @param string|null $subject Email only.
     * @param string|null $sender Code of the sender to send as (a gateway connection, listed on the settings
     *   page); it must be an enabled sender on the channel. Null uses the channel's default sender, else the
     *   gateway's own default for the application. Sent as the gateway's `account`.
     * @param list<string>|string $cc Email only.
     * @param list<string>|string $bcc Email only.
     * @param list<string>|string $replyTo Email only: where replies should go.
     * @param string|null $fromName Email only: the sender's display name on this message.
     * @param string $priority `PRIORITY_*`.
     * @param array<string, mixed> $context Any JSON object; the gateway hands it back untouched.
     * @param bool|null $isAutoSubmitted Email only: marks it `Auto-Submitted: auto-generated`, so out-of-office
     *   replies leave it alone. Null leaves it to the gateway (on for bulk, off otherwise).
     * @param int|null $validFor Seconds from now after which the message is no longer worth sending.
     * @param \DateTimeInterface|null $expiresAt The time after which the message is no longer worth sending;
     *   instead of `validFor`.
     * @throws \InvalidArgumentException When the gateway would refuse the message.
     */
    public function __construct(
        public readonly string $channel,
        array|string $to,
        public readonly string $externalRef,
        public readonly ?string $text = null,
        public readonly ?string $html = null,
        public readonly ?string $subject = null,
        public readonly ?string $sender = null,
        array|string $cc = [],
        array|string $bcc = [],
        array|string $replyTo = [],
        public readonly ?string $fromName = null,
        public readonly string $priority = self::PRIORITY_TRANSACTIONAL,
        public readonly array $context = [],
        public readonly ?bool $isAutoSubmitted = null,
        public readonly ?int $validFor = null,
        public readonly ?DateTimeInterface $expiresAt = null,
    ) {
        $to = self::addresses($to, 'to');
        // An SMS goes to one number in E.164, whatever form it was written in.
        $this->to = $channel === ChannelType::SMS && count($to) === 1 ? [PhoneNumber::parse($to[0])->e164] : $to;
        $this->cc = self::addresses($cc, 'cc');
        $this->bcc = self::addresses($bcc, 'bcc');
        $this->replyTo = self::addresses($replyTo, 'replyTo');
        $this->validate();
    }

    /**
     * An email.
     *
     * @param list<string>|string $to The recipient, or a list of them.
     * @param string $externalRef The application's id for this send.
     * @param string $subject The subject.
     * @param string|null $text Plain-text body.
     * @param string|null $html HTML body.
     * @param mixed ...$options Any other constructor argument by name (`cc:`, `replyTo:`, `context:`…).
     * @return self
     */
    public static function email(
        array|string $to,
        string $externalRef,
        string $subject,
        ?string $text = null,
        ?string $html = null,
        mixed ...$options,
    ): self {
        return new self(ChannelType::EMAIL, $to, $externalRef, $text, $html, $subject, ...$options);
    }

    /**
     * An SMS.
     *
     * @param string $to The phone number, written however the application has it.
     * @param string $externalRef The application's id for this send.
     * @param string $text The text, at most 1,600 characters.
     * @param mixed ...$options Any other constructor argument by name (`sender:`, `validFor:`, `context:`…).
     * @return self
     */
    public static function sms(string $to, string $externalRef, string $text, mixed ...$options): self
    {
        return new self(ChannelType::SMS, $to, $externalRef, $text, ...$options);
    }

    /**
     * The same message, sent as `$sender` when it names none of its own.
     *
     * @param string|null $sender A sender's code.
     * @return self
     */
    public function withDefaultSender(?string $sender): self
    {
        if ($this->sender !== null || $sender === null || $sender === '') {
            return $this;
        }

        return self::fromPayload(['account' => $sender] + $this->toPayload());
    }

    /**
     * The same message with `validFor` turned into the time it ends, so that
     * a retry later keeps the original deadline.
     *
     * @param \DateTimeInterface $now Now.
     * @return self
     */
    public function withDeadlineFrom(DateTimeInterface $now): self
    {
        if ($this->validFor === null) {
            return $this;
        }
        $payload = $this->toPayload();
        unset($payload['valid_for']);
        $payload['expires_at'] = DateTime::createFromInterface($now)
            ->addSeconds($this->validFor)
            ->setTimezone('UTC')
            ->format(DateTimeInterface::ATOM);

        return self::fromPayload($payload);
    }

    /**
     * The JSON body the gateway reads; fields left unset are left out.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'channel' => $this->channel,
            'to' => count($this->to) === 1 ? $this->to[0] : $this->to,
            'external_ref' => $this->externalRef,
            'priority' => $this->priority,
        ];
        $optional = [
            'account' => $this->sender,
            'subject' => $this->subject,
            'text' => $this->text,
            'html' => $this->html,
            'from_name' => $this->fromName,
        ];
        foreach ($optional as $key => $value) {
            if ($value !== null && $value !== '') {
                $payload[$key] = $value;
            }
        }
        foreach (['cc' => $this->cc, 'bcc' => $this->bcc, 'reply_to' => $this->replyTo] as $key => $list) {
            if ($list !== []) {
                $payload[$key] = $list;
            }
        }
        if ($this->context !== []) {
            $payload['context'] = $this->context;
        }
        if ($this->isAutoSubmitted !== null) {
            $payload['auto_submitted'] = $this->isAutoSubmitted;
        }
        if ($this->validFor !== null) {
            $payload['valid_for'] = $this->validFor;
        }
        if ($this->expiresAt !== null) {
            $payload['expires_at'] = DateTime::createFromInterface($this->expiresAt)
                ->setTimezone('UTC')
                ->format(DateTimeInterface::ATOM);
        }

        return $payload;
    }

    /**
     * The request a stored `toPayload()` came from.
     *
     * @param array<string, mixed> $payload A payload.
     * @return self
     * @throws \InvalidArgumentException When it is not a valid message.
     */
    public static function fromPayload(array $payload): self
    {
        $expiresAt = $payload['expires_at'] ?? null;
        $context = $payload['context'] ?? [];

        return new self(
            channel: (string)($payload['channel'] ?? ''),
            to: self::listOrString($payload['to'] ?? ''),
            externalRef: (string)($payload['external_ref'] ?? ''),
            text: isset($payload['text']) ? (string)$payload['text'] : null,
            html: isset($payload['html']) ? (string)$payload['html'] : null,
            subject: isset($payload['subject']) ? (string)$payload['subject'] : null,
            sender: isset($payload['account']) ? (string)$payload['account'] : null,
            cc: self::listOrString($payload['cc'] ?? []),
            bcc: self::listOrString($payload['bcc'] ?? []),
            replyTo: self::listOrString($payload['reply_to'] ?? []),
            fromName: isset($payload['from_name']) ? (string)$payload['from_name'] : null,
            priority: (string)($payload['priority'] ?? self::PRIORITY_TRANSACTIONAL),
            context: is_array($context) ? $context : [],
            isAutoSubmitted: isset($payload['auto_submitted']) ? (bool)$payload['auto_submitted'] : null,
            validFor: isset($payload['valid_for']) ? (int)$payload['valid_for'] : null,
            expiresAt: is_string($expiresAt) ? new DateTime($expiresAt) : null,
        );
    }

    /**
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validate(): void
    {
        if (!in_array($this->channel, ChannelType::VALUES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown messaging channel "%s".', $this->channel));
        }
        if (!in_array($this->priority, self::PRIORITIES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown messaging priority "%s".', $this->priority));
        }
        $refLength = mb_strlen($this->externalRef);
        if (trim($this->externalRef) === '' || $refLength > self::MAX_EXTERNAL_REF_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A message needs an external_ref of 1 to %d characters.',
                self::MAX_EXTERNAL_REF_LENGTH,
            ));
        }
        if ($this->to === []) {
            throw new InvalidArgumentException('A message needs a recipient.');
        }
        if (($this->text ?? '') === '' && ($this->html ?? '') === '') {
            throw new InvalidArgumentException('A message needs a text or an HTML body.');
        }
        foreach (['text' => $this->text, 'html' => $this->html] as $field => $body) {
            if ($body !== null && strlen($body) > self::MAX_BODY_BYTES) {
                throw new InvalidArgumentException(sprintf('The %s body is over 256 KB.', $field));
            }
        }
        if ($this->context !== [] && array_is_list($this->context)) {
            throw new InvalidArgumentException('The context must be a JSON object, not a list.');
        }
        if ($this->validFor !== null && $this->expiresAt !== null) {
            throw new InvalidArgumentException('Give validFor or expiresAt, not both.');
        }
        if ($this->validFor !== null && ($this->validFor < 1 || $this->validFor > self::MAX_VALID_FOR)) {
            throw new InvalidArgumentException(sprintf(
                'validFor must be 1 to %d seconds.',
                self::MAX_VALID_FOR,
            ));
        }

        if ($this->channel === ChannelType::SMS) {
            $this->validateSms();

            return;
        }
        $recipients = count($this->to) + count($this->cc) + count($this->bcc);
        if ($recipients > self::MAX_EMAIL_RECIPIENTS) {
            throw new InvalidArgumentException(sprintf(
                'An email may have at most %d recipients.',
                self::MAX_EMAIL_RECIPIENTS,
            ));
        }
    }

    /**
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateSms(): void
    {
        if (count($this->to) > 1) {
            throw new InvalidArgumentException('An SMS has exactly one recipient.');
        }
        $emailOnly = [
            'html' => $this->html !== null,
            'subject' => $this->subject !== null,
            'cc' => $this->cc !== [],
            'bcc' => $this->bcc !== [],
            'replyTo' => $this->replyTo !== [],
            'fromName' => $this->fromName !== null,
            'isAutoSubmitted' => $this->isAutoSubmitted !== null,
        ];
        foreach ($emailOnly as $field => $isGiven) {
            if ($isGiven) {
                throw new InvalidArgumentException(sprintf('%s is for email only, not for an SMS.', $field));
            }
        }
        if (mb_strlen((string)$this->text) > self::MAX_SMS_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'An SMS may be at most %d characters.',
                self::MAX_SMS_LENGTH,
            ));
        }
    }

    /**
     * @param list<string>|string $addresses One address or a list.
     * @param string $field The argument, for the error.
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    private static function addresses(array|string $addresses, string $field): array
    {
        $list = [];
        foreach (is_string($addresses) ? [$addresses] : $addresses as $address) {
            if (!is_string($address)) {
                throw new InvalidArgumentException(sprintf('Every %s address must be a string.', $field));
            }
            $address = trim($address);
            if ($address !== '') {
                $list[] = $address;
            }
        }

        return $list;
    }

    /**
     * @param mixed $value A stored address field.
     * @return list<string>|string
     */
    private static function listOrString(mixed $value): array|string
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        return (string)$value;
    }
}
