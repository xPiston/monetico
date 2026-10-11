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
 * A call to `recredit_paiement`: send money back.
 *
 * The platform needs to know three amounts to let a refund through - the
 * order's total, what is being refunded now, and *either* the maximum still
 * refundable or the total already refunded. Not neither; the request is
 * rejected without one of them, and sending the wrong one is what `-52` and
 * `-35` are about. So the two shapes are two named constructors and the choice
 * is made at the call site, where the shop's own records are.
 *
 * `of()` identifies the payment precisely, with the authorisation number from
 * the notification and the date the payment was remitted. `wholeOrder()` is
 * the documented shortcut for cards and Apple Pay, which refunds the entire
 * order without either - and the two fields have to be omitted *together*,
 * which is what error `-50` says when they are not.
 *
 * Two operational notes that are not in the code and will cost an afternoon
 * if nobody mentions them: refunds only work from IP addresses the merchant
 * has had allow-listed by Monetico's support, and each address has a daily
 * quota agreed with them.
 */
final class RefundRequest
{
    private function __construct(
        public readonly Reference $reference,
        public readonly Amount $orderAmount,
        public readonly Amount $toRefund,
        public readonly ?Amount $refundable,
        public readonly ?Amount $alreadyRefunded,
        public readonly DateTimeImmutable $orderedOn,
        public readonly ?DateTimeImmutable $remittedOn,
        public readonly ?string $authorisationNumber,
        public readonly DateTimeImmutable $requestedAt,
        public readonly Language $language,
        public readonly ?string $fileNumber,
        public readonly ?string $invoiceType,
    ) {
        if (! $this->reference->isServiceSafe()) {
            throw InvalidField::because(
                'reference',
                'the refund service accepts letters and digits only, however payable the reference was',
                $this->reference->value(),
            );
        }

        if ($refundable === null && $alreadyRefunded === null) {
            throw InvalidField::because(
                'montant_possible',
                'a refund needs either the amount still refundable or the amount already refunded',
                '',
            );
        }

        foreach (['montant_recredit' => $toRefund, 'montant_possible' => $refundable, 'montant_deja_recredite' => $alreadyRefunded] as $field => $amount) {
            if ($amount !== null && ! $amount->isSameCurrencyAs($orderAmount)) {
                throw InvalidField::mixedCurrencies($field, $orderAmount->currency()->code(), $amount->currency()->code());
            }
        }

        if ($toRefund->isZero()) {
            throw InvalidField::because('montant_recredit', 'a refund of nothing is not a refund', $toRefund->toMonetico());
        }
    }

    /**
     * Refund part or all of an identified payment.
     *
     * `$refundable` is the maximum the platform will still allow against that
     * authorisation number, which the shop works out by subtracting what it
     * has already refunded. `$alreadyRefunded` says the same thing the other
     * way round; one of the two is required.
     */
    public static function of(
        Reference $reference,
        Amount $orderAmount,
        Amount $toRefund,
        DateTimeImmutable $orderedOn,
        DateTimeImmutable $remittedOn,
        string $authorisationNumber,
        ?Amount $refundable = null,
        ?Amount $alreadyRefunded = null,
        ?DateTimeImmutable $requestedAt = null,
        Language $language = Language::French,
        ?string $fileNumber = null,
        ?string $invoiceType = null,
    ): self {
        return new self(
            reference: $reference,
            orderAmount: $orderAmount,
            toRefund: $toRefund,
            refundable: $refundable ?? ($alreadyRefunded === null ? $orderAmount : null),
            alreadyRefunded: $alreadyRefunded,
            orderedOn: $orderedOn,
            remittedOn: $remittedOn,
            authorisationNumber: $authorisationNumber,
            requestedAt: $requestedAt ?? new DateTimeImmutable,
            language: $language,
            fileNumber: $fileNumber,
            invoiceType: $invoiceType,
        );
    }

    /**
     * Refund a whole card or Apple Pay order without the authorisation number
     * and the remittance date.
     *
     * The platform answers `-51` when the order does not allow it, which is
     * the signal to go and look the payment up and use `of()` instead.
     */
    public static function wholeOrder(
        Reference $reference,
        Amount $orderAmount,
        DateTimeImmutable $orderedOn,
        ?Amount $alreadyRefunded = null,
        ?DateTimeImmutable $requestedAt = null,
        Language $language = Language::French,
    ): self {
        return new self(
            reference: $reference,
            orderAmount: $orderAmount,
            toRefund: $orderAmount,
            refundable: null,
            alreadyRefunded: $alreadyRefunded ?? Amount::zero($orderAmount->currency()),
            orderedOn: $orderedOn,
            remittedOn: null,
            authorisationNumber: null,
            requestedAt: $requestedAt ?? new DateTimeImmutable,
            language: $language,
            fileNumber: null,
            invoiceType: null,
        );
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
            'montant_recredit' => $this->toRefund->toMonetico(),
            'reference' => $this->reference->value(),
        ];

        if ($this->remittedOn !== null) {
            $fields['date_remise'] = DateFormat::day($this->remittedOn, $timezone);
        }

        if ($this->authorisationNumber !== null) {
            $fields['num_autorisation'] = $this->authorisationNumber;
        }

        if ($this->refundable !== null) {
            $fields['montant_possible'] = $this->refundable->toMonetico();
        }

        if ($this->alreadyRefunded !== null) {
            $fields['montant_deja_recredite'] = $this->alreadyRefunded->toMonetico();
        }

        if ($this->fileNumber !== null) {
            $fields['numero_dossier'] = $this->fileNumber;
        }

        if ($this->invoiceType !== null) {
            $fields['facture'] = $this->invoiceType;
        }

        return $fields;
    }
}
