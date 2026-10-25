<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase\Model;

use Cake\TestSuite\TestCase;
use RockSoftware\MessagingClient\Gateway\GatewayConnection;
use RockSoftware\MessagingClient\Model\Entity\ChannelType;
use RockSoftware\MessagingClient\Model\Table\SendersTable;
use RockSoftware\MessagingClient\TestSuite\Fixture\MessagingClientFixtures;

class SendersTableTest extends TestCase
{
    protected array $fixtures = MessagingClientFixtures::ALL;

    private SendersTable $senders;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \RockSoftware\MessagingClient\Model\Table\SendersTable $senders */
        $senders = $this->fetchTable('RockSoftware/MessagingClient.Senders');
        $this->senders = $senders;
    }

    public function testARefreshAddsUpdatesAndKeepsSendersTheGatewayNoLongerLists(): void
    {
        $this->senders->refresh([
            $this->connection('app-email', 'email', 'app@example.test'),
            $this->connection('app-sms', 'sms', '+358401234567'),
        ]);
        $this->senders->setDefault('app-sms');

        $this->senders->refresh([
            $this->connection('app-email', 'email', 'new@example.test', isTest: true),
            $this->connection('app-whatsapp', 'whatsapp', '+358401234567'),
        ]);

        $email = $this->senders->findByCode('app-email');
        $this->assertSame('new@example.test', $email?->identity);
        $this->assertTrue($email->is_test);
        $this->assertTrue($email->is_available);
        $this->assertTrue($email->is_enabled);
        $this->assertNotNull($email->refreshed_at);

        $sms = $this->senders->findByCode('app-sms');
        $this->assertNotNull($sms);
        $this->assertFalse($sms->is_available);
        $this->assertTrue($sms->is_default);
        $this->assertNull($this->senders->defaultFor(ChannelType::SMS), 'An unavailable default is not used.');

        $this->assertNull($this->senders->findByCode('app-whatsapp'), 'A channel the plugin does not know is left out.');
    }

    public function testAChannelHasAtMostOneDefault(): void
    {
        $this->senders->refresh([
            $this->connection('a-email', 'email', 'a@example.test'),
            $this->connection('b-email', 'email', 'b@example.test'),
            $this->connection('c-sms', 'sms', '+358401234567'),
        ]);

        $this->senders->setDefault('a-email');
        $this->senders->setDefault('c-sms');
        $this->senders->setDefault('b-email');

        $defaults = $this->senders->find()->where(['is_default' => true])->all()->extract('code')->toList();
        sort($defaults);
        $this->assertSame(['b-email', 'c-sms'], $defaults);
        $this->assertSame('b-email', $this->senders->defaultFor(ChannelType::EMAIL)?->code);
    }

    public function testADisabledSenderIsNobodysDefault(): void
    {
        $this->senders->refresh([$this->connection('a-email', 'email', 'a@example.test')]);
        $this->senders->setDefault('a-email');

        $this->senders->setEnabled('a-email', false);

        $this->assertFalse($this->senders->findByCode('a-email')?->is_default);
        $this->assertNull($this->senders->defaultFor(ChannelType::EMAIL));
    }

    /**
     * @param string $code Code.
     * @param string $channel Channel.
     * @param string $identity Identity.
     * @param bool $isTest Test mode.
     * @return \RockSoftware\MessagingClient\Gateway\GatewayConnection
     */
    private function connection(string $code, string $channel, string $identity, bool $isTest = false): GatewayConnection
    {
        return new GatewayConnection($code, $channel, $code, $identity, 'Example App', $isTest, true, true, false);
    }
}
