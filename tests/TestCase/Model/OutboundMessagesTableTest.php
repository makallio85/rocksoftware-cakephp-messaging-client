<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase\Model;

use Cake\TestSuite\TestCase;
use RockSoftware\MessagingClient\Messenger;
use RockSoftware\MessagingClient\Model\Table\OutboundMessagesTable;
use RockSoftware\MessagingClient\SendRequest;
use RockSoftware\MessagingClient\TestSuite\Fixture\MessagingClientFixtures;
use RockSoftware\MessagingClient\TestSuite\MessagingTestTrait;

/**
 * How an application reads its own outbound messages back.
 */
class OutboundMessagesTableTest extends TestCase
{
    use MessagingTestTrait;

    protected array $fixtures = MessagingClientFixtures::ALL;

    private OutboundMessagesTable $messages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
        /** @var \RockSoftware\MessagingClient\Model\Table\OutboundMessagesTable $messages */
        $messages = $this->fetchTable('RockSoftware/MessagingClient.OutboundMessages');
        $this->messages = $messages;
        $messenger = new Messenger();
        $sends = [
            ['change-request-17-approver-4', ['change_request_id' => 17]],
            ['change-request-17-approver-5', ['change_request_id' => 17, 'note' => 'x']],
            ['change-request-170-approver-4', ['change_request_id' => 170]],
            ['change_request-1', ['change_request_id' => '17']],
            ['nested', ['outer' => ['change_request_id' => 17]]],
        ];
        foreach ($sends as [$ref, $context]) {
            $messenger->send(SendRequest::email(
                to: 'a@example.test',
                externalRef: $ref,
                subject: 's',
                text: 'x',
                context: $context,
            ));
        }
    }

    public function testByAContextValueOfItsOwnType(): void
    {
        $refs = $this->messages->find('withContext', key: 'change_request_id', value: 17)
            ->all()
            ->extract('external_ref')
            ->toList();

        $this->assertSame(['change-request-17-approver-5', 'change-request-17-approver-4'], $refs);
    }

    public function testByAnExternalRefPrefixWithItsWildcardsTakenLiterally(): void
    {
        $refs = $this->messages->find('withExternalRefPrefix', prefix: 'change-request-17-')
            ->all()
            ->extract('external_ref')
            ->toList();
        $this->assertSame(['change-request-17-approver-5', 'change-request-17-approver-4'], $refs);

        $this->assertSame(
            ['change_request-1'],
            $this->messages->find('withExternalRefPrefix', prefix: 'change_')->all()->extract('external_ref')->toList(),
        );
    }
}
