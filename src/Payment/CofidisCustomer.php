<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment;

use DateTimeInterface;
use Piston\Monetico\Exception\InvalidField;

/**
 * The customer details that pre-fill a COFIDIS credit application.
 *
 * Paying in three or four instalments through COFIDIS means the customer fills
 * in a credit form on the partner's site. These fields carry what the shop
 * already knows, so the customer retypes their address instead of abandoning.
 * Everything here is optional and needs the partner on the contract.
 *
 * **The values are hexadecimal.** The documentation is explicit - "these
 * values must be encoded in hexadecimal before being sent" - and it is the
 * only place in the protocol where that is true. `bin2hex` of the UTF-8 bytes
 * is what this does, and the seal is computed over the encoded value like any
 * other field, because by then it *is* the value.
 *
 * One honest caveat on the field names. The documentation's field table calls
 * the first one `civiliteclient`, and its own HTML example on the next page
 * calls it `civilite`. The table is used here, because the rest of the table's
 * names appear verbatim in the example. If your terminal disagrees, the
 * sandbox says so out loud - a field name the platform does not recognise
 * produces an explicit error on the payment page in the test environment -
 * and `new PaymentOptions(['civilite' => bin2hex('MR')])` sends the other one.
 */
final class CofidisCustomer
{
    /** @var array<string, string> */
    private array $fields = [];

    private function __construct() {}

    public static function create(): self
    {
        return new self;
    }

    /** `MR`, `MME` or `MLLE`. */
    public function withTitle(string $title): self
    {
        $title = strtoupper(trim($title));

        if (! in_array($title, ['MR', 'MME', 'MLLE'], true)) {
            throw InvalidField::because('civiliteclient', 'expected MR, MME or MLLE', $title);
        }

        return $this->set('civiliteclient', $title);
    }

    public function withName(string $lastName, string $firstName): self
    {
        $pattern = '/^[a-zA-Z\x{00C0}-\x{00FF}\- ]{1,50}$/u';

        foreach (['nomclient' => $lastName, 'prenomclient' => $firstName] as $field => $value) {
            if (preg_match($pattern, $value) !== 1) {
                throw InvalidField::because($field, 'expected 1 to 50 letters, spaces or hyphens', $value);
            }
        }

        return $this->set('nomclient', $lastName)->set('prenomclient', $firstName);
    }

    public function withAddress(
        string $address,
        string $postcode,
        string $city,
        string $country = 'FR',
        ?string $complement = null,
    ): self {
        $rules = [
            'adresseclient' => ['/^.{1,100}$/u', 'expected up to 100 characters', $address],
            'codepostalclient' => ['/^[a-zA-Z0-9]{1,10}$/', 'expected up to 10 letters or digits', $postcode],
            'villeclient' => ['/^[a-zA-Z]{1,50}$/', 'expected up to 50 letters', $city],
            'paysclient' => ['/^[a-zA-Z]{2}$/', 'expected a two-letter country code', $country],
        ];

        if ($complement !== null) {
            $rules['complementadresseclient'] = ['/^.{1,50}$/u', 'expected up to 50 characters', $complement];
        }

        $customer = $this;

        foreach ($rules as $field => [$pattern, $rule, $value]) {
            if (preg_match($pattern, $value) !== 1) {
                throw InvalidField::because($field, $rule, $value);
            }

            $customer = $customer->set($field, $value);
        }

        return $customer;
    }

    public function withPhones(?string $landline = null, ?string $mobile = null): self
    {
        $customer = $this;

        foreach (['telephonefixeclient' => $landline, 'telephonemobileclient' => $mobile] as $field => $value) {
            if ($value === null) {
                continue;
            }

            if (preg_match('/^[0-9]{2,20}$/', $value) !== 1) {
                throw InvalidField::because($field, 'expected 2 to 20 digits and nothing else', $value);
            }

            $customer = $customer->set($field, $value);
        }

        return $customer;
    }

    /** The date of birth, which the partner wants as `YYYYMMDD`. */
    public function bornOn(DateTimeInterface $date, ?string $birthDepartment = null): self
    {
        $customer = $this->set('datenaissanceclient', $date->format('Ymd'));

        if ($birthDepartment === null) {
            return $customer;
        }

        if (preg_match('/^[a-zA-Z0-9]{1,50}$/', $birthDepartment) !== 1) {
            throw InvalidField::because('departementnaissanceclient', 'expected up to 50 letters or digits', $birthDepartment);
        }

        return $customer->set('departementnaissanceclient', $birthDepartment);
    }

    /** COFIDIS pre-scoring, when the shop has one. */
    public function withPreScore(string $score): self
    {
        if (preg_match('/^[0-9]{1,16}$/', $score) !== 1) {
            throw InvalidField::because('prescore', 'expected digits', $score);
        }

        return $this->set('prescore', $score);
    }

    /**
     * The fields, hexadecimal, ready to be sealed and sent.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        return array_map(static fn (string $value): string => bin2hex($value), $this->fields);
    }

    /**
     * The values before encoding, which is what a test or a log wants.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->fields;
    }

    private function set(string $field, string $value): self
    {
        $clone = clone $this;
        $clone->fields[$field] = $value;

        return $clone;
    }
}
