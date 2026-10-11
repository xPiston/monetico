<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment\Context;

use JsonException;
use Piston\Monetico\Exception\InvalidField;

/**
 * `contexte_commande`: the order's context as base64-encoded JSON.
 *
 * It is what 3D Secure 2 runs on. The field is mandatory on the payment form
 * outside the virtual-terminal mode, and the richer it is the fewer customers
 * get stopped by a challenge they will abandon.
 *
 * Encoding detail that matters: the JSON is UTF-8 with its accents and slashes
 * left alone, because the base64 of the document is what gets sealed, and the
 * seal is computed over whatever string this produces. Any re-encoding between
 * sealing and sending breaks the payment.
 */
final class OrderContext
{
    private function __construct(
        private readonly BillingAddress $billing,
        private readonly ?ShippingAddress $shipping,
        private readonly ?ShoppingCart $cart,
        private readonly ?ClientInformation $client,
    ) {}

    public static function for(
        BillingAddress $billing,
        ?ShippingAddress $shipping = null,
        ?ShoppingCart $cart = null,
        ?ClientInformation $client = null,
    ): self {
        return new self($billing, $shipping, $cart, $client);
    }

    /** @return array<string, array<string, mixed>> */
    public function toArray(): array
    {
        $document = ['billing' => $this->billing->toArray()];

        // Absent objects are left out entirely rather than sent empty, which
        // the documentation forbids.
        if ($this->shipping !== null && $this->shipping->toArray() !== []) {
            $document['shipping'] = $this->shipping->toArray();
        }

        if ($this->cart !== null && $this->cart->toArray() !== []) {
            $document['shoppingCart'] = $this->cart->toArray();
        }

        if ($this->client !== null && $this->client->toArray() !== []) {
            $document['client'] = $this->client->toArray();
        }

        return $document;
    }

    public function toJson(): string
    {
        try {
            return json_encode(
                $this->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            // Reachable only through invalid UTF-8 in an attribute, which is
            // worth saying out loud: it would otherwise become a seal error.
            throw InvalidField::because('contexte_commande', 'could not be encoded as JSON ('.$e->getMessage().')', '');
        }
    }

    /** The field value: the JSON document, base64-encoded. */
    public function toField(): string
    {
        return base64_encode($this->toJson());
    }

    /**
     * Reads a context back - for tests, for a support ticket, and for the
     * `authentification` field of a notification, which is encoded the same
     * way.
     *
     * @return array<mixed>|null
     */
    public static function decode(string $field): ?array
    {
        $json = base64_decode(trim($field), true);

        if ($json === false) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
