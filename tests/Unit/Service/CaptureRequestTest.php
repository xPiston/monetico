<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\Reference;
use Piston\Monetico\Service\CaptureRequest;
use Piston\Monetico\Tests\Support\Fixtures;

final class CaptureRequestTest extends TestCase
{
    /** The partial capture printed in the documentation, field for field. */
    public function test_it_builds_the_documented_partial_capture(): void
    {
        $request = CaptureRequest::of(
            reference: Reference::of('ABERTPY00145'),
            orderAmount: Amount::fromString('100.00EUR'),
            toCapture: Amount::fromString('62.00EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03', new DateTimeZone('Europe/Paris')),
            alreadyCaptured: Amount::fromString('0EUR'),
            remaining: Amount::fromString('38.00EUR'),
            requestedAt: new DateTimeImmutable('2006-12-05 11:55:23', new DateTimeZone('Europe/Paris')),
        );

        $this->assertSame([
            'version' => '3.0',
            'TPE' => '1234567',
            'societe' => 'monSite1',
            'lgue' => 'FR',
            'date' => '05/12/2006:11:55:23',
            'date_commande' => '03/12/2006',
            'montant' => '100.00EUR',
            'montant_a_capturer' => '62.00EUR',
            'montant_deja_capture' => '0EUR',
            'montant_restant' => '38.00EUR',
            'reference' => 'ABERTPY00145',
        ], $request->fields(Fixtures::credentials()));
    }

    public function test_a_full_capture_leaves_nothing_remaining(): void
    {
        $fields = CaptureRequest::of(
            reference: Reference::of('ABERTPY00145'),
            orderAmount: Amount::fromMinor(10000, 'EUR'),
            toCapture: Amount::fromMinor(10000, 'EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
        )->fields(Fixtures::credentials());

        $this->assertSame('100.00EUR', $fields['montant_a_capturer']);
        $this->assertSame('0.00EUR', $fields['montant_restant']);
        $this->assertSame('0.00EUR', $fields['montant_deja_capture']);
    }

    /**
     * The platform requires the three amounts to account for the order, and
     * answers `montant errone` when they do not - with no further detail.
     */
    public function test_amounts_that_do_not_account_for_the_order_are_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('montant_a_capturer + montant_deja_capture + montant_restant = 70.00EUR, but the order is 100.00EUR');

        CaptureRequest::of(
            reference: Reference::of('ABERTPY00145'),
            orderAmount: Amount::fromMinor(10000, 'EUR'),
            toCapture: Amount::fromMinor(7000, 'EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
        );
    }

    public function test_a_second_capture_accounts_for_the_first(): void
    {
        $request = CaptureRequest::of(
            reference: Reference::of('ABERTPY00145'),
            orderAmount: Amount::fromMinor(10000, 'EUR'),
            toCapture: Amount::fromMinor(3800, 'EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
            alreadyCaptured: Amount::fromMinor(6200, 'EUR'),
        );

        $this->assertSame('38.00EUR', $request->fields(Fixtures::credentials())['montant_a_capturer']);
        $this->assertFalse($request->isCancellation());
    }

    /**
     * A cancellation does *not* satisfy the sum rule - the documented example
     * cancels a 100 EUR order with three zeroes - so insisting on it would
     * refuse the one request the documentation shows.
     */
    public function test_a_cancellation_is_three_zeroes_and_is_not_held_to_the_sum(): void
    {
        $fields = CaptureRequest::cancel(
            reference: Reference::of('ABERTPY00145'),
            orderAmount: Amount::fromString('100.00EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03', new DateTimeZone('Europe/Paris')),
            requestedAt: new DateTimeImmutable('2006-12-05 11:55:23', new DateTimeZone('Europe/Paris')),
        )->fields(Fixtures::credentials());

        $this->assertSame('100.00EUR', $fields['montant']);
        $this->assertSame('0.00EUR', $fields['montant_a_capturer']);
        $this->assertSame('0.00EUR', $fields['montant_restant']);
        $this->assertArrayNotHasKey('stoprecurrence', $fields);
    }

    public function test_a_cancellation_carries_what_was_already_captured(): void
    {
        $fields = CaptureRequest::cancel(
            reference: Reference::of('ABERTPY00145'),
            orderAmount: Amount::fromMinor(10000, 'EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
            alreadyCaptured: Amount::fromMinor(6200, 'EUR'),
        )->fields(Fixtures::credentials());

        $this->assertSame('62.00EUR', $fields['montant_deja_capture']);
    }

    public function test_stopping_a_recurrence_adds_the_one_field_that_does_it(): void
    {
        $request = CaptureRequest::stopRecurrence(
            reference: Reference::of('ABERTPY00145'),
            orderAmount: Amount::fromMinor(10000, 'EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
        );

        $fields = $request->fields(Fixtures::credentials());

        $this->assertSame('OUI', $fields['stoprecurrence']);
        $this->assertSame('0.00EUR', $fields['montant_a_capturer']);
        $this->assertTrue($request->isCancellation());
    }

    /**
     * The reference trap: payable with a dash, not collectable with one. Found
     * here rather than on the day the shop tries to take the money.
     */
    public function test_a_reference_the_service_will_not_accept_is_refused_here(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('letters and digits only, however payable the reference was');

        CaptureRequest::of(
            reference: Reference::of('CMD-2026-0042'),
            orderAmount: Amount::fromMinor(100, 'EUR'),
            toCapture: Amount::fromMinor(100, 'EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
        );
    }

    public function test_two_currencies_in_one_capture_are_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('montant_a_capturer is in USD but the order is in EUR');

        CaptureRequest::of(
            reference: Reference::of('REF001'),
            orderAmount: Amount::fromMinor(10000, 'EUR'),
            toCapture: Amount::fromMinor(10000, 'USD'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
            remaining: Amount::fromMinor(0, 'USD'),
        );
    }

    public function test_it_carries_the_pre_authorisation_fields(): void
    {
        $fields = CaptureRequest::of(
            reference: Reference::of('REF001'),
            orderAmount: Amount::fromMinor(100, 'EUR'),
            toCapture: Amount::fromMinor(100, 'EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03'),
            fileNumber: '20150901PRE1',
            invoiceType: 'noshow',
            expectPhoneCallDetail: true,
        )->fields(Fixtures::credentials());

        $this->assertSame('20150901PRE1', $fields['numero_dossier']);
        $this->assertSame('noshow', $fields['facture']);
        $this->assertSame('oui', $fields['phonie']);
    }

    public function test_the_dates_are_formatted_in_the_platform_timezone(): void
    {
        $fields = CaptureRequest::of(
            reference: Reference::of('REF001'),
            orderAmount: Amount::fromMinor(100, 'EUR'),
            toCapture: Amount::fromMinor(100, 'EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03 23:30:00', new DateTimeZone('UTC')),
            requestedAt: new DateTimeImmutable('2006-12-05 23:30:00', new DateTimeZone('UTC')),
        )->fields(Fixtures::credentials());

        $this->assertSame('04/12/2006', $fields['date_commande']);
        $this->assertSame('06/12/2006:00:30:00', $fields['date']);
    }
}
