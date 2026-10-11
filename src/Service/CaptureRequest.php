<?php

declare(strict_types=1);

namespace Piston\Monetico\Service;

use DateTimeImmutable;
use DateTimeZone;
use Piston\Monetico\Configuration\Credentials;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\DateFormat;
use Piston\Monetico\Field\Language;
use Piston\Monetico\Field\Reference;
use Piston\Monetico\Payment\PaymentRequest;

/**
 * A call to `capture_paiement`: collect money that was authorised earlier -
 * or decide not to.
 *
 * The same endpoint does three jobs, and which one it does depends entirely on
 * the amounts. That is the single most confusing thing about this service, so
 * it is three named constructors here rather than one with a flag:
 *
 * - `of()` collects an amount, in full or in part. The platform requires that
 *   what is being collected, what has already been collected and what is left
 *   add up to the order - it is how it knows a partial capture is deliberate -
 *   and that sum is checked here, before the request leaves, because the
 *   answer otherwise is `montant errone` and no further explanation.
 * - `cancel()` releases the authorisation: nothing to capture, nothing left.
 *   It deliberately does *not* check the sum, because a cancellation does not
 *   satisfy it - the documented example cancels a 100 EUR order with three
 *   zeroes - and a library that insisted would refuse the one request the
 *   documentation shows.
 * - `stopRecurrence()` is a cancellation that also ends a subscription.
 *
 * One more trap, inherited from the reference: the capture service accepts
 * letters and digits in a reference and nothing else, while the payment form
 * accepts any printable character. A reference with a dash is payable and not
 * collectable, and the time to find that out is now.
 */
final class CaptureRequest
{
    private function __construct(
        public readonly Reference $reference,
        public readonly Amount $orderAmount,
        public readonly Amount $toCapture,
        public readonly Amount $alreadyCaptured,
        public readonly Amount $remaining,
        public readonly DateTimeImmutable $orderedOn,
        public readonly DateTimeImmutable $requestedAt,
        public readonly Language $language,
        public readonly bool $stopRecurrence,
        public readonly ?string $fileNumber,
        public readonly ?string $invoiceType,
        public readonly bool $expectPhoneCallDetail,
    ) {
        if (! $this->reference->isServiceSafe()) {
            throw InvalidField::because(
                'reference',
                'the capture service accepts letters and digits only, however payable the reference was',
                $this->reference->value(),
            );
        }

        self::assertOneCurrency($orderAmount, $toCapture, $alreadyCaptured, $remaining);
    }

    /**
     * Checked before anything is added up, so that a request in two
     * currencies is reported as what it is rather than as arithmetic that
     * does not work.
     */
    private static function assertOneCurrency(
        Amount $orderAmount,
        Amount $toCapture,
        Amount $alreadyCaptured,
        Amount $remaining,
    ): void {
        foreach ([
            'montant_a_capturer' => $toCapture,
            'montant_deja_capture' => $alreadyCaptured,
            'montant_restant' => $remaining,
        ] as $field => $amount) {
            if (! $amount->isSameCurrencyAs($orderAmount)) {
                throw InvalidField::mixedCurrencies(
                    $field,
                    $orderAmount->currency()->code(),
                    $amount->currency()->code(),
                );
            }
        }
    }

    /**
     * Collect `$toCapture` of an order.
     *
     * `$alreadyCaptured` is what previous captures took and `$remaining` is
     * what is left for later ones - zero on a full capture, which is also what
     * tells the platform no further capture is coming.
     */
    public static function of(
        Reference $reference,
        Amount $orderAmount,
        Amount $toCapture,
        DateTimeImmutable $orderedOn,
        ?Amount $alreadyCaptured = null,
        ?Amount $remaining = null,
        ?DateTimeImmutable $requestedAt = null,
        Language $language = Language::French,
        ?string $fileNumber = null,
        ?string $invoiceType = null,
        bool $expectPhoneCallDetail = false,
    ): self {
        $alreadyCaptured ??= Amount::zero($orderAmount->currency());
        $remaining ??= Amount::zero($orderAmount->currency());

        self::assertOneCurrency($orderAmount, $toCapture, $alreadyCaptured, $remaining);

        $sum = $toCapture->plus($alreadyCaptured)->plus($remaining);

        if (! $sum->equals($orderAmount)) {
            throw InvalidField::amountsDoNotAddUp($orderAmount->toMonetico(), $sum->toMonetico());
        }

        return new self(
            reference: $reference,
            orderAmount: $orderAmount,
            toCapture: $toCapture,
            alreadyCaptured: $alreadyCaptured,
            remaining: $remaining,
            orderedOn: $orderedOn,
            requestedAt: $requestedAt ?? new DateTimeImmutable,
            language: $language,
            stopRecurrence: false,
            fileNumber: $fileNumber,
            invoiceType: $invoiceType,
            expectPhoneCallDetail: $expectPhoneCallDetail,
        );
    }

    /**
     * Give up on an authorisation: the goods are out of stock, the customer
     * changed their mind.
     *
     * `$alreadyCaptured` must be what the platform has on record for the
     * order, which for an order never collected is zero.
     */
    public static function cancel(
        Reference $reference,
        Amount $orderAmount,
        DateTimeImmutable $orderedOn,
        ?Amount $alreadyCaptured = null,
        ?DateTimeImmutable $requestedAt = null,
        Language $language = Language::French,
    ): self {
        return self::cancellation($reference, $orderAmount, $orderedOn, $alreadyCaptured, $requestedAt, $language, false);
    }

    /** A cancellation that also ends the subscription behind it. */
    public static function stopRecurrence(
        Reference $reference,
        Amount $orderAmount,
        DateTimeImmutable $orderedOn,
        ?Amount $alreadyCaptured = null,
        ?DateTimeImmutable $requestedAt = null,
        Language $language = Language::French,
    ): self {
        return self::cancellation($reference, $orderAmount, $orderedOn, $alreadyCaptured, $requestedAt, $language, true);
    }

    private static function cancellation(
        Reference $reference,
        Amount $orderAmount,
        DateTimeImmutable $orderedOn,
        ?Amount $alreadyCaptured,
        ?DateTimeImmutable $requestedAt,
        Language $language,
        bool $stopRecurrence,
    ): self {
        $zero = Amount::zero($orderAmount->currency());

        return new self(
            reference: $reference,
            orderAmount: $orderAmount,
            toCapture: $zero,
            alreadyCaptured: $alreadyCaptured ?? $zero,
            remaining: $zero,
            orderedOn: $orderedOn,
            requestedAt: $requestedAt ?? new DateTimeImmutable,
            language: $language,
            stopRecurrence: $stopRecurrence,
            fileNumber: null,
            invoiceType: null,
            expectPhoneCallDetail: false,
        );
    }

    public function isCancellation(): bool
    {
        return $this->toCapture->isZero() && $this->remaining->isZero();
    }

    /** @return array<string, string> */
    public function fields(Credentials $credentials, ?DateTimeZone $timezone = null): array
    {
        $fields = [
            'version' => PaymentRequest::VERSION,
            'TPE' => $credentials->terminal->value(),
            'societe' => $credentials->company->value(),
            'lgue' => $this->language->value,
            'date' => DateFormat::dateTime($this->requestedAt, $timezone),
            'date_commande' => DateFormat::day($this->orderedOn, $timezone),
            'montant' => $this->orderAmount->toMonetico(),
            'montant_a_capturer' => $this->toCapture->toMonetico(),
            'montant_deja_capture' => $this->alreadyCaptured->toMonetico(),
            'montant_restant' => $this->remaining->toMonetico(),
            'reference' => $this->reference->value(),
        ];

        if ($this->stopRecurrence) {
            // The documented value is the French "OUI", in upper case.
            $fields['stoprecurrence'] = 'OUI';
        }

        if ($this->fileNumber !== null) {
            $fields['numero_dossier'] = $this->fileNumber;
        }

        if ($this->invoiceType !== null) {
            $fields['facture'] = $this->invoiceType;
        }

        if ($this->expectPhoneCallDetail) {
            $fields['phonie'] = 'oui';
        }

        return $fields;
    }
}
