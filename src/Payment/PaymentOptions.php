<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment;

use Piston\Monetico\Exception\InvalidField;

/**
 * The optional fields of a payment form.
 *
 * Immutable and chainable - `PaymentOptions::none()->inIframe()->asStatement(...)` -
 * so an application can build them once per shop and reuse them per order.
 *
 * Every option here is also a line in the contract: the card alias needs
 * "express payment", the iframe needs "iframe", disabling 3D Secure needs the
 * option to be enabled on the terminal. Sending one the contract does not
 * cover gets the form refused, not ignored, which is why they are opt-in one
 * by one rather than a single array of defaults.
 */
final class PaymentOptions
{
    /**
     * The fields a payment request builds itself, which an option must not
     * quietly replace.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'version', 'TPE', 'societe', 'date', 'montant', 'reference', 'lgue', 'MAC',
        'contexte_commande', 'mail', 'texte-libre', 'url_retour_ok', 'url_retour_err',
        'nbrech', 'dateech1', 'dateech2', 'dateech3', 'dateech4',
        'montantech1', 'montantech2', 'montantech3', 'montantech4',
    ];

    /**
     * Fields this class does not know about yet.
     *
     * An escape hatch, on purpose: Monetico adds optional fields to the
     * payment form between editions of its documentation, and waiting for a
     * release of this library to use one would be a poor reason not to. What
     * goes in here is sealed and sent like any other option.
     *
     * The mandatory fields are refused, because the only thing overriding one
     * here could do is contradict the order it was built from.
     *
     * @param  array<string, string>  $fields
     */
    public function __construct(private readonly array $fields = [])
    {
        foreach (array_keys($fields) as $name) {
            if (in_array($name, self::RESERVED, true)) {
                throw InvalidField::because(
                    'options',
                    sprintf('"%s" belongs to the order itself, not to its options', $name),
                    $name,
                );
            }
        }
    }

    public static function none(): self
    {
        return new self;
    }

    /** The minimalist form, for an iframe or a mobile webview. */
    public function inIframe(): self
    {
        return $this->set('mode_affichage', DisplayMode::Iframe->value);
    }

    public function withThreeDSecureChallenge(ThreeDSecureChallenge $challenge): self
    {
        return $this->set('ThreeDSecureChallenge', $challenge->value);
    }

    /**
     * Turns 3D Secure off for this order.
     *
     * Needs the option on the terminal, and moves the liability for a
     * fraudulent payment from the issuer to the shop. There are good reasons
     * to use it - a recurring payment the customer already authenticated - and
     * the method is named after what it does so that nobody reaches for it by
     * accident.
     */
    public function withoutThreeDSecure(): self
    {
        return $this->set('3dsdebrayable', '1');
    }

    /**
     * What the customer will read on their bank statement.
     *
     * Worth setting for a shop whose trade name is not its brand: an
     * unrecognised line on a statement is the most common cause of a
     * chargeback that is not fraud at all.
     *
     * The locality is `city\zip\country` - the country in ISO 3166-1
     * alpha-3 - and the platform allows 32 characters for each.
     */
    public function asStatement(string $tradeName, ?string $locality = null): self
    {
        if (preg_match('/^[A-Za-z0-9 ]{1,32}$/', $tradeName) !== 1) {
            throw InvalidField::because('libelleMonetique', 'expected up to 32 letters, digits or spaces', $tradeName);
        }

        $options = $this->set('libelleMonetique', $tradeName);

        if ($locality === null) {
            return $options;
        }

        if (preg_match('/^[-A-Za-z0-9 ]+\\\\[-A-Z a-z0-9]*\\\\[A-Za-z]{3}$/', $locality) !== 1) {
            throw InvalidField::because(
                'libelleMonetiqueLocalite',
                'expected city\\zip\\country, such as Strasbourg\\67000\\FRA',
                $locality,
            );
        }

        return $options->set('libelleMonetiqueLocalite', $locality);
    }

    public function withoutPaymentMethods(AlternativePaymentMethod ...$methods): self
    {
        return $this->set(
            'desactivemoyenpaiement',
            implode(',', array_map(static fn (AlternativePaymentMethod $m): string => $m->value, $methods)),
        );
    }

    /** Pay through a partner directly, without the payment page offering a card. */
    public function through(AlternativePaymentMethod $method): self
    {
        return $this->set('protocole', $method->value);
    }

    /**
     * The customer's stored card ("express payment").
     *
     * The alias identifies the customer, not the card: the platform keeps the
     * card against it, and the shop never sees a card number. `$forceEntry`
     * asks for the card to be typed again anyway - which is what you want the
     * first time, and when a customer says their card has changed.
     */
    public function withCardAlias(string $alias, bool $forceEntry = false): self
    {
        if (preg_match('/^[A-Za-z0-9]{1,64}$/', $alias) !== 1) {
            throw InvalidField::because('aliascb', 'expected 1 to 64 alphanumeric characters', $alias);
        }

        return $this->set('aliascb', $alias)->set('forcesaisiecb', $forceEntry ? '1' : '0');
    }

    /**
     * Pre-fills the COFIDIS credit application for a payment in three or four
     * instalments through the partner.
     *
     * See CofidisCustomer: the values are hexadecimal, which is a requirement
     * of these fields alone.
     */
    public function withCofidisCustomer(CofidisCustomer $customer): self
    {
        $options = $this;

        foreach ($customer->fields() as $field => $value) {
            $options = $options->set($field, $value);
        }

        return $options;
    }

    /** `numero_dossier`, mandatory on a terminal set up for pre-authorisation. */
    public function withPreAuthorisationFile(string $number): self
    {
        if (preg_match('/^[A-Za-z0-9]{1,12}$/', $number) !== 1) {
            throw InvalidField::because('numero_dossier', 'expected up to 12 alphanumeric characters', $number);
        }

        return $this->set('numero_dossier', $number);
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }

    private function set(string $field, string $value): self
    {
        return new self([...$this->fields, $field => $value]);
    }
}
