<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Model\Table;

use Cake\Database\Schema\TableSchemaInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessage;
use RockSoftware\MessagingClient\Model\Entity\OutboundMessageStatusType;

/**
 * The delivery log.
 *
 * @property \RockSoftware\MessagingClient\Model\Table\ChannelTypesTable $ChannelTypes
 * @property \RockSoftware\MessagingClient\Model\Table\OutboundMessageStatusTypesTable $OutboundMessageStatusTypes
 */
class OutboundMessagesTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_messaging_client_outbound_messages');
        $this->setPrimaryKey('id');
        $this->setDisplayField('external_ref');
        $this->setEntityClass(OutboundMessage::class);
        $this->addBehavior('Timestamp');

        $this->belongsTo('ChannelTypes', [
            'className' => ChannelTypesTable::class,
            'foreignKey' => 'channel_type_id',
        ]);
        $this->belongsTo('OutboundMessageStatusTypes', [
            'className' => OutboundMessageStatusTypesTable::class,
            'foreignKey' => 'outbound_message_status_type_id',
        ]);
    }

    /**
     * @param \Cake\Database\Schema\TableSchemaInterface $schema The schema.
     * @return \Cake\Database\Schema\TableSchemaInterface
     */
    public function getSchema(): TableSchemaInterface
    {
        $schema = parent::getSchema();
        $schema->setColumnType('context', 'json');
        $schema->setColumnType('payload', 'json');

        return $schema;
    }

    /**
     * Messages with their channel and status.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findWithTypes(SelectQuery $query): SelectQuery
    {
        return $query->contain(['ChannelTypes', 'OutboundMessageStatusTypes']);
    }

    /**
     * Messages whose `context` holds `$key` with exactly `$value` at its top
     * level, newest first:
     * `find('withContext', key: 'change_request_id', value: 17)`.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query.
     * @param string $key A top-level context key.
     * @param string|float|int|bool|null $value Its value, of the type it was sent with (`17` is not `'17'`).
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findWithContext(
        SelectQuery $query,
        string $key,
        string|int|float|bool|null $value,
    ): SelectQuery {
        // The context is stored as compact JSON, so a top-level pair is this
        // exact fragment. The fragment narrows the rows in SQL; the same pair
        // nested deeper would match it too, so the rows are checked again.
        $fragment = substr((string)json_encode([$key => $value]), 1, -1);

        return $this->findWithTypes($query)
            ->where([$this->aliasField('context') . " LIKE :rsmc_context ESCAPE '!'"])
            ->bind(':rsmc_context', '%' . self::escapeLike($fragment) . '%', 'string')
            ->orderBy([$this->aliasField('id') => 'DESC'])
            ->formatResults(fn($results) => $results->filter(
                fn($message): bool => is_array($message->context)
                    && array_key_exists($key, $message->context)
                    && $message->context[$key] === $value,
            ));
    }

    /**
     * Messages whose `external_ref` starts with `$prefix`, newest first:
     * `find('withExternalRefPrefix', prefix: 'change-request-17-')`.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query.
     * @param string $prefix The start of the refs.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findWithExternalRefPrefix(SelectQuery $query, string $prefix): SelectQuery
    {
        return $this->findWithTypes($query)
            ->where([$this->aliasField('external_ref') . " LIKE :rsmc_prefix ESCAPE '!'"])
            ->bind(':rsmc_prefix', self::escapeLike($prefix) . '%', 'string')
            ->orderBy([$this->aliasField('id') => 'DESC']);
    }

    /**
     * @param string $value A literal.
     * @return string It, safe inside a LIKE pattern escaped with `!` (the same in every database).
     */
    private static function escapeLike(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    /**
     * The most recent messages first, for the settings page.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query.
     * @param int $limit How many.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findLatest(SelectQuery $query, int $limit = 20): SelectQuery
    {
        return $this->findWithTypes($query)
            ->orderBy([$this->aliasField('id') => 'DESC'])
            ->limit($limit);
    }

    /**
     * Pending messages whose next attempt is due, oldest first.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query.
     * @param \Cake\I18n\DateTime $now Now.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findDue(SelectQuery $query, DateTime $now): SelectQuery
    {
        return $this->findWithTypes($query)
            ->where([
                $this->aliasField('outbound_message_status_type_id')
                    => $this->OutboundMessageStatusTypes->idFor(OutboundMessageStatusType::PENDING),
                'OR' => [
                    $this->aliasField('next_attempt_at') . ' IS' => null,
                    $this->aliasField('next_attempt_at') . ' <=' => $now,
                ],
            ])
            ->orderBy([$this->aliasField('id') => 'ASC']);
    }

    /**
     * The message recorded under an `external_ref`, or null.
     *
     * @param string $externalRef The application's id for the send.
     * @return \RockSoftware\MessagingClient\Model\Entity\OutboundMessage|null
     */
    public function findByExternalRef(string $externalRef): ?OutboundMessage
    {
        /** @var \RockSoftware\MessagingClient\Model\Entity\OutboundMessage|null */
        return $this->find('withTypes')->where([$this->aliasField('external_ref') => $externalRef])->first();
    }

    /**
     * Claims a pending message for one attempt: moves its next attempt to
     * `$until` only if nobody else has moved it since it was read, so two
     * workers never send the same row at the same moment. (Were they to, the
     * shared `external_ref` would still keep the gateway from sending twice.)
     *
     * @param \RockSoftware\MessagingClient\Model\Entity\OutboundMessage $message The message as read.
     * @param \Cake\I18n\DateTime $until When another worker may try it, should this attempt die.
     * @return bool Whether this caller has it.
     */
    public function claim(OutboundMessage $message, DateTime $until): bool
    {
        $conditions = [
            'id' => $message->id,
            'outbound_message_status_type_id' => $this->OutboundMessageStatusTypes
                ->idFor(OutboundMessageStatusType::PENDING),
        ];
        $conditions[] = $message->next_attempt_at === null
            ? ['next_attempt_at IS' => null]
            : ['next_attempt_at' => $message->next_attempt_at];

        $isClaimed = $this->updateAll(['next_attempt_at' => $until], $conditions) === 1;
        if ($isClaimed) {
            $message->next_attempt_at = $until;
            $message->setDirty('next_attempt_at', false);
        }

        return $isClaimed;
    }
}
