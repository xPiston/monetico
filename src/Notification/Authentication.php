<?php

declare(strict_types=1);

namespace Piston\Monetico\Notification;

use Piston\Monetico\Payment\Context\OrderContext;

/**
 * The `authentification` field: what 3D Secure did, as base64-encoded JSON.
 *
 * The one value to keep is `liabilityShift`. It answers the only question that
 * matters after a disputed payment: who pays. With the shift, a fraudulent
 * payment is the issuer's problem; without it, it is the shop's. A payment can
 * be perfectly accepted and still leave the shop carrying the risk - 3D Secure
 * disabled, an exemption granted, a card scheme that does not support it - and
 * the notification is the only place that says so.
 *
 * Stored alongside the order, it is also the evidence a chargeback is fought
 * with months later.
 */
final class Authentication
{
    /** @param array<mixed> $document */
    private function __construct(private readonly array $document) {}

    public static function fromField(string $field): ?self
    {
        $document = OrderContext::decode($field);

        return $document === null ? null : new self($document);
    }

    public function protocol(): ?string
    {
        return $this->string('protocol');
    }

    public function status(): ?string
    {
        return $this->string('status');
    }

    public function version(): ?string
    {
        return $this->string('version');
    }

    /** Did the issuer take on the fraud risk? Null when the field is absent. */
    public function liabilityShift(): ?bool
    {
        $value = $this->document['liabilityShift'] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['y', 'yes', 'true', '1', 'oui'], true);
        }

        return null;
    }

    /** @return array<mixed> */
    public function details(): array
    {
        $details = $this->document['details'] ?? [];

        return is_array($details) ? $details : [];
    }

    /** @return array<mixed> */
    public function toArray(): array
    {
        return $this->document;
    }

    private function string(string $key): ?string
    {
        $value = $this->document[$key] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
