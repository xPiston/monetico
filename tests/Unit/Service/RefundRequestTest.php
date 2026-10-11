<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\Reference;
use Piston\Monetico\Service\RefundRequest;
use Piston\Monetico\Tests\Support\Fixtures;

final class RefundRequestTest extends TestCase
{
    /** The partial refund printed in the documentation, field for field. */
    public function test_it_builds_the_documented_partial_refund(): void
    {
        $paris = new DateTimeZone('Europe/Paris');

        $fields = RefundRequest::of(
            reference: Reference::of('ABERTPY00145'),
            orderAmount: Amount::fromString('100.00EUR'),
            toRefund: Amount::fromString('32.00EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03', $paris),
            remittedOn: new DateTimeImmutable('2006-12-04', $paris),
            authorisationNumber: '1234A6',
            refundable: Amount::fromString('100EUR'),
            requestedAt: new DateTimeImmutable('2006-12-05 11:55:23', $paris),
        )->fields(Fixtures::credentials());

        $this->assertSame([
            'version' => '3.0',
            'TPE' => '1234567',
            'societe' => 'monSite1',
            'lgue' => 'FR',
            'date' => '05/12/2006:11:55:23',
            'date_commande' => '03/12/2006',
            'montant' => '100.00EUR',
            'montant_recredit' => '32.00EUR',
            'reference' => 'ABERTPY00145',
            'date_remise' => '04/12/2006',
            'num_autorisation' => '1234A6',
            'montant_possible' => '100EUR',
        ], $fields);
    }

    /**
     * The whole-order shortcut for cards and Apple Pay: the authorisation
     * number and the remittance date are omitted *together*, which is what
     * error -50 is about.
     */
    public function test_the_whole_order_refund_omits_both_fields_together(): void
    {
        $fields = RefundRequest::wholeOrder(
            reference: Reference::of('ABERTPY00145'),
            orderAmount: Amount::fromString('100.00EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03', new DateTimeZone('Europe/Paris')),
        )->fields(Fixtures::credentials());

        $this->assertArrayNotHasKey('num_autorisation', $fields);
        $this->assertArrayNotHasKey('date_remise', $fields);
        $this->assertSame('100.00EUR', $fields['montant_recredit']);
        $this->assertSame('0.00EUR', $fields['montant_deja_recredite']);
    }

    public function test_a_whole_order_refund_can_account_for_an_earlier_one(): void
    {
        $fields = RefundRequest::wholeOrder(
            reference: Reference::of('REF001'),
            orderAmount: Amount::fromMinor(10000, 'EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
            alreadyRefunded: Amount::fromMinor(2000, 'EUR'),
        )->fields(Fixtures::credentials());

        $this->assertSame('20.00EUR', $fields['montant_deja_recredite']);
    }

    public function test_the_refundable_amount_defaults_to_the_whole_order(): void
    {
        $fields = RefundRequest::of(
            reference: Reference::of('REF001'),
            orderAmount: Amount::fromMinor(10000, 'EUR'),
            toRefund: Amount::fromMinor(3200, 'EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
            remittedOn: new DateTimeImmutable('2006-12-04'),
            authorisationNumber: '1234A6',
        )->fields(Fixtures::credentials());

        $this->assertSame('100.00EUR', $fields['montant_possible']);
        $this->assertArrayNotHasKey('montant_deja_recredite', $fields);
    }

    public function test_the_already_refunded_amount_can_be_sent_instead(): void
    {
        $fields = RefundRequest::of(
            reference: Reference::of('REF001'),
            orderAmount: Amount::fromMinor(10000, 'EUR'),
            toRefund: Amount::fromMinor(3200, 'EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
            remittedOn: new DateTimeImmutable('2006-12-04'),
            authorisationNumber: '1234A6',
            alreadyRefunded: Amount::fromMinor(1000, 'EUR'),
        )->fields(Fixtures::credentials());

        $this->assertSame('10.00EUR', $fields['montant_deja_recredite']);
        $this->assertArrayNotHasKey('montant_possible', $fields);
    }

    public function test_a_refund_of_nothing_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('a refund of nothing is not a refund');

        RefundRequest::of(
            reference: Reference::of('REF001'),
            orderAmount: Amount::fromMinor(10000, 'EUR'),
            toRefund: Amount::zero('EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
            remittedOn: new DateTimeImmutable('2006-12-04'),
            authorisationNumber: '1234A6',
        );
    }

    public function test_a_reference_the_service_will_not_accept_is_refused_here(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('letters and digits only');

        RefundRequest::wholeOrder(
            reference: Reference::of('CMD/2026/42'),
            orderAmount: Amount::fromMinor(100, 'EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
        );
    }

    public function test_two_currencies_in_one_refund_are_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('montant_recredit is in USD but the order is in EUR');

        RefundRequest::of(
            reference: Reference::of('REF001'),
            orderAmount: Amount::fromMinor(10000, 'EUR'),
            toRefund: Amount::fromMinor(100, 'USD'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
            remittedOn: new DateTimeImmutable('2006-12-04'),
            authorisationNumber: '1234A6',
        );
    }

    public function test_it_carries_the_pre_authorisation_fields(): void
    {
        $fields = RefundRequest::of(
            reference: Reference::of('REF001'),
            orderAmount: Amount::fromMinor(100, 'EUR'),
            toRefund: Amount::fromMinor(100, 'EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
            remittedOn: new DateTimeImmutable('2006-12-04'),
            authorisationNumber: '1234A6',
            fileNumber: '20150901PRE1',
            invoiceType: 'complementaire',
        )->fields(Fixtures::credentials());

        $this->assertSame('20150901PRE1', $fields['numero_dossier']);
        $this->assertSame('complementaire', $fields['facture']);
    }
}
