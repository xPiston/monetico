<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Payment;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Payment\CofidisCustomer;
use Piston\Monetico\Payment\PaymentOptions;

final class CofidisCustomerTest extends TestCase
{
    public function test_nothing_is_sent_for_a_customer_nobody_described(): void
    {
        $this->assertSame([], CofidisCustomer::create()->fields());
    }

    /**
     * The one thing that makes these fields different from every other field
     * in the protocol.
     */
    public function test_the_values_are_hexadecimal(): void
    {
        $fields = CofidisCustomer::create()->withTitle('MR')->fields();

        $this->assertSame(['civiliteclient' => '4d52'], $fields);
        $this->assertSame('MR', hex2bin($fields['civiliteclient']));
    }

    public function test_it_encodes_the_documented_example(): void
    {
        $customer = CofidisCustomer::create()
            ->withName('lachoucroute', 'cestbon')
            ->withAddress('rue des saucisses', '67200', 'Strasbourg');

        $fields = $customer->fields();

        // The hexadecimal strings printed in the documentation's own example
        // form, for the same values.
        $this->assertSame('6c6163686f7563726f757465', $fields['nomclient']);
        $this->assertSame('63657374626f6e', $fields['prenomclient']);
        $this->assertSame('7275652064657320736175636973736573', $fields['adresseclient']);
    }

    public function test_an_accented_name_is_encoded_as_its_utf8_bytes(): void
    {
        $fields = CofidisCustomer::create()->withName('Lefèvre', 'Jérémy')->fields();

        $this->assertSame('Lefèvre', hex2bin($fields['nomclient']));
        $this->assertSame('Jérémy', hex2bin($fields['prenomclient']));
    }

    public function test_it_carries_the_whole_customer(): void
    {
        $customer = CofidisCustomer::create()
            ->withTitle('MME')
            ->withName('Lovelace', 'Ada')
            ->withAddress('20 rue des champs', '67200', 'Strasbourg', 'FR', 'Appartement B')
            ->withPhones('0312345678', '0612345678')
            ->bornOn(new DateTimeImmutable('1987-03-27'), '67')
            ->withPreScore('1234567');

        $this->assertSame([
            'civiliteclient' => 'MME',
            'nomclient' => 'Lovelace',
            'prenomclient' => 'Ada',
            'adresseclient' => '20 rue des champs',
            'codepostalclient' => '67200',
            'villeclient' => 'Strasbourg',
            'paysclient' => 'FR',
            'complementadresseclient' => 'Appartement B',
            'telephonefixeclient' => '0312345678',
            'telephonemobileclient' => '0612345678',
            'datenaissanceclient' => '19870327',
            'departementnaissanceclient' => '67',
            'prescore' => '1234567',
        ], $customer->toArray());

        $this->assertCount(13, $customer->fields());
    }

    public function test_the_date_of_birth_is_eight_digits(): void
    {
        $this->assertSame(
            '19870327',
            CofidisCustomer::create()->bornOn(new DateTimeImmutable('1987-03-27 23:00:00'))->toArray()['datenaissanceclient'],
        );
    }

    public function test_it_is_immutable(): void
    {
        $empty = CofidisCustomer::create();
        $named = $empty->withTitle('MR');

        $this->assertSame([], $empty->fields());
        $this->assertNotSame([], $named->fields());
    }

    public function test_an_unknown_title_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('expected MR, MME or MLLE');

        CofidisCustomer::create()->withTitle('Dr');
    }

    public function test_a_title_is_read_in_any_case(): void
    {
        $this->assertSame('MME', CofidisCustomer::create()->withTitle(' mme ')->toArray()['civiliteclient']);
    }

    public function test_a_name_with_a_digit_in_it_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('nomclient');

        CofidisCustomer::create()->withName('Lovelace2', 'Ada');
    }

    public function test_a_city_the_partner_would_refuse_is_refused_here(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('villeclient: expected up to 50 letters');

        CofidisCustomer::create()->withAddress('1 rue de Test', '67200', 'Saint-Étienne');
    }

    public function test_a_phone_number_with_separators_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('expected 2 to 20 digits and nothing else');

        CofidisCustomer::create()->withPhones('03 12 34 56 78');
    }

    public function test_a_pre_score_that_is_not_a_number_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('prescore');

        CofidisCustomer::create()->withPreScore('high');
    }

    public function test_the_options_carry_it_hexadecimal_and_all(): void
    {
        $options = PaymentOptions::none()->withCofidisCustomer(
            CofidisCustomer::create()->withTitle('MR')->withPhones(null, '0612345678'),
        );

        $this->assertSame([
            'civiliteclient' => '4d52',
            'telephonemobileclient' => '30363132333435363738',
        ], $options->fields());
    }
}
