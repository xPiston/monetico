<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Payment;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\Language;
use Piston\Monetico\Field\Reference;
use Piston\Monetico\Payment\Context\BillingAddress;
use Piston\Monetico\Payment\Context\OrderContext;
use Piston\Monetico\Payment\Instalment;
use Piston\Monetico\Payment\Instalments;
use Piston\Monetico\Payment\PaymentOptions;
use Piston\Monetico\Payment\PaymentRequest;
use Piston\Monetico\Tests\Support\Fixtures;

final class PaymentRequestTest extends TestCase
{
    public function test_it_builds_the_mandatory_fields(): void
    {
        $fields = $this->request()->fields(Fixtures::credentials());

        $this->assertSame([
            'version' => '3.0',
            'TPE' => '1234567',
            'societe' => 'monSite1',
            'date' => '24/05/2019:10:00:25',
            'montant' => '62.73EUR',
            'reference' => 'REF001',
            'lgue' => 'FR',
        ], $fields);
    }

    public function test_the_version_is_the_only_one_the_platform_accepts(): void
    {
        $this->assertSame('3.0', PaymentRequest::VERSION);
    }

    /**
     * An optional field that is not set is left out rather than sent empty.
     * Both are allowed; the seal covers what is sent, so the shorter the
     * message the less there is to disagree about.
     */
    public function test_an_optional_field_with_no_value_is_absent(): void
    {
        $fields = $this->request()->fields(Fixtures::credentials());

        foreach (['mail', 'texte-libre', 'url_retour_ok', 'url_retour_err', 'nbrech', 'contexte_commande'] as $absent) {
            $this->assertArrayNotHasKey($absent, $fields);
        }
    }

    public function test_it_carries_the_optional_fields_it_is_given(): void
    {
        $request = new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(6273, 'EUR'),
            orderedAt: $this->orderedAt(),
            language: Language::English,
            customerEmail: 'ada@example.com',
            freeText: 'Collection point',
            successUrl: 'https://shop.test/ok?ref=REF001',
            errorUrl: 'https://shop.test/ko?ref=REF001',
        );

        $fields = $request->fields(Fixtures::credentials());

        $this->assertSame('ada@example.com', $fields['mail']);
        $this->assertSame('Collection point', $fields['texte-libre']);
        $this->assertSame('https://shop.test/ok?ref=REF001', $fields['url_retour_ok']);
        $this->assertSame('https://shop.test/ko?ref=REF001', $fields['url_retour_err']);
        $this->assertSame('EN', $fields['lgue']);
    }

    /**
     * The field is `url_retour_ok`, in French, as every HTML example in the
     * documentation shows - including in the English edition, whose field
     * table translates the name itself and calls it `url_return_ok`. A field
     * name the platform does not recognise gets the whole form refused.
     */
    public function test_the_return_url_fields_keep_their_french_names(): void
    {
        $fields = (new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: $this->orderedAt(),
            successUrl: 'https://shop.test/ok',
        ))->fields(Fixtures::credentials());

        $this->assertArrayHasKey('url_retour_ok', $fields);
        $this->assertArrayNotHasKey('url_return_ok', $fields);
    }

    public function test_it_carries_the_order_context_as_base64(): void
    {
        $request = new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: $this->orderedAt(),
            context: OrderContext::for(BillingAddress::create('3 rue de l\'église', 'Ostheim', '68150', 'FR')),
        );

        $fields = $request->fields(Fixtures::credentials());

        $this->assertArrayHasKey('contexte_commande', $fields);
        $this->assertSame(
            ['billing' => ['addressLine1' => '3 rue de l\'église', 'city' => 'Ostheim', 'postalCode' => '68150', 'country' => 'FR']],
            OrderContext::decode($fields['contexte_commande']),
        );
    }

    public function test_it_carries_the_options(): void
    {
        $request = new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: $this->orderedAt(),
            options: PaymentOptions::none()->inIframe(),
        );

        $this->assertSame('iframe', $request->fields(Fixtures::credentials())['mode_affichage']);
    }

    public function test_it_carries_the_instalments(): void
    {
        $request = new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(10000, 'EUR'),
            orderedAt: $this->orderedAt(),
            instalments: Instalments::of(
                new Instalment(new DateTimeImmutable('2019-05-24', new DateTimeZone('Europe/Paris')), Amount::fromMinor(5000, 'EUR')),
                new Instalment(new DateTimeImmutable('2019-06-24', new DateTimeZone('Europe/Paris')), Amount::fromMinor(5000, 'EUR')),
            ),
        );

        $fields = $request->fields(Fixtures::credentials());

        $this->assertSame('2', $fields['nbrech']);
        $this->assertSame('24/05/2019', $fields['dateech1']);
        $this->assertSame('50.00EUR', $fields['montantech1']);
        $this->assertSame('24/06/2019', $fields['dateech2']);
        $this->assertSame('50.00EUR', $fields['montantech2']);
    }

    /**
     * The platform requires that the instalments add up to the order, and
     * answers a rejected form when they do not.
     */
    public function test_instalments_that_do_not_add_up_to_the_order_are_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('the instalments add up to 60.00EUR but the order is 100.00EUR');

        new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(10000, 'EUR'),
            orderedAt: $this->orderedAt(),
            instalments: Instalments::of(
                new Instalment(new DateTimeImmutable('2019-05-24'), Amount::fromMinor(3000, 'EUR')),
                new Instalment(new DateTimeImmutable('2019-06-24'), Amount::fromMinor(3000, 'EUR')),
            ),
        );
    }

    public function test_an_email_that_is_not_an_email_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('mail: expected an email address');

        new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: $this->orderedAt(),
            customerEmail: 'not-an-email',
        );
    }

    /**
     * A line break inside a field would split the string the seal is computed
     * over, and the documentation forbids the two characters outright.
     */
    public function test_free_text_with_a_line_break_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('must not contain a line break');

        new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: $this->orderedAt(),
            freeText: "two\nlines",
        );
    }

    public function test_free_text_longer_than_the_field_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('at most 3200 characters');

        new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: $this->orderedAt(),
            freeText: str_repeat('a', 3201),
        );
    }

    public function test_the_order_date_is_converted_to_the_platform_timezone(): void
    {
        $request = new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: new DateTimeImmutable('2019-06-24 08:00:25', new DateTimeZone('UTC')),
        );

        $this->assertSame('24/06/2019:10:00:25', $request->fields(Fixtures::credentials())['date']);
        $this->assertSame(
            '24/06/2019:08:00:25',
            $request->fields(Fixtures::credentials(), new DateTimeZone('UTC'))['date'],
        );
    }

    /**
     * Nothing here escapes anything. The seal is computed over these values,
     * and the documentation says to encode them for HTML immediately *after*
     * that - see PaymentForm.
     */
    public function test_the_values_are_raw(): void
    {
        $request = new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: $this->orderedAt(),
            freeText: 'Rue de l\'Église & co <3',
        );

        $this->assertSame('Rue de l\'Église & co <3', $request->fields(Fixtures::credentials())['texte-libre']);
    }

    private function request(): PaymentRequest
    {
        return new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(6273, 'EUR'),
            orderedAt: $this->orderedAt(),
        );
    }

    private function orderedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2019-05-24 10:00:25', new DateTimeZone('Europe/Paris'));
    }
}
