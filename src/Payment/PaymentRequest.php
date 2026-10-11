<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment;

use DateTimeImmutable;
use DateTimeZone;
use Piston\Monetico\Configuration\Credentials;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\DateFormat;
use Piston\Monetico\Field\Language;
use Piston\Monetico\Field\Reference;
use Piston\Monetico\Payment\Context\OrderContext;

/**
 * One order, ready to be sealed into a form.
 *
 * Everything the platform needs and nothing it does not: the fields that are
 * left empty are left *out*. The documentation allows both - an optional field
 * "may not be provided" or "may be provided empty" - and the choice matters
 * because the seal covers exactly what is sent. Sending fourteen empty fields,
 * as the documentation's own example string does, only widens the surface on
 * which a shop and the platform can disagree about what was in the message.
 *
 * The version is pinned to 3.0 because that is the only value the platform
 * accepts, and it is not a constructor argument for the same reason.
 */
final class PaymentRequest
{
    public const VERSION = '3.0';

    public function __construct(
        public readonly Reference $reference,
        public readonly Amount $amount,
        public readonly DateTimeImmutable $orderedAt,
        public readonly Language $language = Language::French,
        public readonly ?OrderContext $context = null,
        public readonly ?string $customerEmail = null,
        public readonly ?string $freeText = null,
        public readonly ?string $successUrl = null,
        public readonly ?string $errorUrl = null,
        public readonly ?Instalments $instalments = null,
        public readonly PaymentOptions $options = new PaymentOptions,
    ) {
        if ($customerEmail !== null && preg_match('/^.+@.+\..+$/', $customerEmail) !== 1) {
            throw InvalidField::because('mail', 'expected an email address', $customerEmail);
        }

        if ($freeText !== null && preg_match('/[\r\n]/', $freeText) === 1) {
            // A line break inside a field would split the seal's own string.
            throw InvalidField::because('texte-libre', 'must not contain a line break', $freeText);
        }

        if ($freeText !== null && mb_strlen($freeText) > 3200) {
            throw InvalidField::because('texte-libre', 'expected at most 3200 characters', (string) mb_strlen($freeText));
        }

        if ($instalments !== null && ! $instalments->total()->equals($amount)) {
            throw InvalidField::because(
                'montantech*',
                sprintf('the instalments add up to %s but the order is %s', $instalments->total(), $amount),
                (string) $instalments->total(),
            );
        }
    }

    /**
     * The form's fields, raw - not HTML-escaped, not URL-encoded.
     *
     * This is deliberate and is the single most important detail of the whole
     * exchange. The seal is computed over these values; the documentation then
     * says to encode them for HTML *immediately after* the MAC calculation. Do
     * it the other way round - seal `Rue de l&#x27;Église` instead of
     * `Rue de l'Église` - and every payment from an address with an apostrophe
     * fails while the rest work. PaymentForm::toHtml() does the escaping, at
     * the last possible moment.
     *
     * @return array<string, string>
     */
    public function fields(Credentials $credentials, ?DateTimeZone $timezone = null): array
    {
        $fields = [
            'version' => self::VERSION,
            'TPE' => $credentials->terminal->value(),
            'societe' => $credentials->company->value(),
            'date' => DateFormat::dateTime($this->orderedAt, $timezone),
            'montant' => $this->amount->toMonetico(),
            'reference' => $this->reference->value(),
            'lgue' => $this->language->value,
        ];

        if ($this->context !== null) {
            $fields['contexte_commande'] = $this->context->toField();
        }

        $optional = [
            'mail' => $this->customerEmail,
            'texte-libre' => $this->freeText,
            'url_retour_ok' => $this->successUrl,
            'url_retour_err' => $this->errorUrl,
        ];

        foreach ($optional as $name => $value) {
            if ($value !== null && $value !== '') {
                $fields[$name] = $value;
            }
        }

        if ($this->instalments !== null) {
            $fields = [...$fields, ...$this->instalments->fields($timezone)];
        }

        return [...$fields, ...$this->options->fields()];
    }
}
