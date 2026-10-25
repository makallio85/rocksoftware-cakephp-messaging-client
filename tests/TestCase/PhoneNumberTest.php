<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RockSoftware\MessagingClient\PhoneNumber;
use RockSoftware\MessagingClient\SendRequest;

class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function finnishForms(): array
    {
        return [
            'national' => ['0401234567'],
            'spaced' => ['040 123 4567'],
            'dashed' => ['040-1234567'],
            'bracketed area' => ['(040) 123 4567'],
            'international' => ['+358401234567'],
            'international spaced' => ['+358 40 123 4567'],
            'bracketed trunk zero' => ['+358 (0)40 123 4567'],
            'double zero' => ['00358 40 123 4567'],
        ];
    }

    #[DataProvider('finnishForms')]
    public function testTheWaysAFinnishNumberIsWrittenAreOneNumber(string $raw): void
    {
        $this->assertSame('+358401234567', PhoneNumber::parse($raw)->e164);
    }

    public function testAForeignCountryCodeIsKept(): void
    {
        $this->assertSame('+46701740605', PhoneNumber::parse('+46 70 174 06 05')->e164);
    }

    public function testTheDefaultCountryIsConfigurable(): void
    {
        $this->assertSame('+46701740605', PhoneNumber::parse('070-174 06 05', '0046')->e164);

        Configure::write('RockSoftwareMessagingClient.defaultCountryCode', '+46');
        $this->assertSame('+46701740605', PhoneNumber::parse('070-174 06 05')->e164);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notNumbers(): array
    {
        return [
            'empty' => ['  '],
            'words' => ['ei numeroa'],
            'too short' => ['12345'],
            'zero' => ['0'],
            'short international' => ['+12'],
            'too long' => ['040 123 456 789 012 345'],
            'sender name' => ['Elisa'],
        ];
    }

    #[DataProvider('notNumbers')]
    public function testWhatCannotBeANumberIsRefusedRatherThanGuessed(string $raw): void
    {
        $this->expectException(InvalidArgumentException::class);
        PhoneNumber::parse($raw);
    }

    public function testAnSmsGoesToTheNumberInE164(): void
    {
        $request = SendRequest::sms(to: '040 123 4567', externalRef: 'r', text: 'x');

        $this->assertSame('+358401234567', $request->toPayload()['to']);
    }

    public function testAnSmsToSomethingThatIsNotANumberIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SendRequest::sms(to: '12345', externalRef: 'r', text: 'x');
    }
}
