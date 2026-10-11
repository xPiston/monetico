<?php

declare(strict_types=1);

namespace Piston\Monetico\Notification;

use DateTimeImmutable;
use Piston\Monetico\Field\DateFormat;

/**
 * What the notification says about the card that paid.
 *
 * Every field is optional, and which ones arrive depends on the contract: the
 * BIN, the country, the card fingerprint and the customer's IP only come with
 * the fraud prevention module, and the masked number only for card payments -
 * a PayPal payment has none.
 *
 * Two of these are worth storing against the order. `maskedNumber` is what
 * lets a support agent confirm "the card ending 42" without the shop ever
 * holding a card number. `fingerprint` (`hpancb`) is the same card, always the
 * same value, for this merchant only - so it identifies a repeat customer, or
 * a card trying eleven different billing addresses, without being a card
 * number either.
 */
final class Card
{
    public function __construct(
        public readonly ?CardBrand $brand = null,
        public readonly ?string $maskedNumber = null,
        public readonly ?string $expiry = null,
        public readonly ?string $bin = null,
        public readonly ?string $fingerprint = null,
        public readonly ?bool $cryptogramEntered = null,
        public readonly ?CardUsage $usage = null,
        public readonly ?AccountType $accountType = null,
        public readonly ?bool $isVirtual = null,
        public readonly ?bool $savedUnderAlias = null,
        public readonly ?string $issuingCountry = null,
    ) {}

    /** @param array<string, string> $fields */
    public static function fromFields(array $fields): self
    {
        $read = static fn (string $name): ?string => isset($fields[$name]) && $fields[$name] !== ''
            ? $fields[$name]
            : null;

        $yesNo = static function (?string $value): ?bool {
            if ($value === null) {
                return null;
            }

            // `oui`/`non` on these fields, `1`/`0` on cbenregistree.
            return in_array(strtolower($value), ['oui', 'yes', '1'], true);
        };

        return new self(
            brand: ($brand = $read('brand')) !== null ? CardBrand::tryFromWire($brand) : null,
            maskedNumber: $read('cbmasquee'),
            expiry: $read('vld'),
            bin: $read('bincb'),
            fingerprint: $read('hpancb'),
            cryptogramEntered: $yesNo($read('cvx')),
            usage: ($usage = $read('usage')) !== null ? CardUsage::tryFromWire($usage) : null,
            accountType: ($type = $read('typecompte')) !== null ? AccountType::tryFromWire($type) : null,
            isVirtual: $yesNo($read('ecard')),
            savedUnderAlias: $yesNo($read('cbenregistree')),
            issuingCountry: $read('originecb'),
        );
    }

    /**
     * The last moment this card is valid, from the `MMYY` field.
     *
     * The `!` in the format matters: without it PHP fills the missing day from
     * today, and asking for the expiry of a card in `0219` on the 31st of a
     * month lands in March.
     */
    public function expiresAt(): ?DateTimeImmutable
    {
        if ($this->expiry === null || preg_match('/^\d{4}$/', $this->expiry) !== 1) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!my', $this->expiry, DateFormat::platformTimezone());

        if (! $parsed instanceof DateTimeImmutable) {
            return null;
        }

        return $parsed->modify('last day of this month')->setTime(23, 59, 59);
    }
}
