<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Payment\Context;

use PHPUnit\Framework\TestCase;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Payment\Context\AuthenticationMethod;
use Piston\Monetico\Payment\Context\BillingAddress;
use Piston\Monetico\Payment\Context\CartItem;
use Piston\Monetico\Payment\Context\ClientInformation;
use Piston\Monetico\Payment\Context\DeliveryTimeframe;
use Piston\Monetico\Payment\Context\OrderContext;
use Piston\Monetico\Payment\Context\ShipIndicator;
use Piston\Monetico\Payment\Context\ShippingAddress;
use Piston\Monetico\Payment\Context\ShoppingCart;

final class OrderContextTest extends TestCase
{
    public function test_the_billing_address_alone_is_a_valid_context(): void
    {
        $context = OrderContext::for(BillingAddress::create('3 rue de l\'église', 'Ostheim', '68150', 'FR'));

        $this->assertSame([
            'billing' => [
                'addressLine1' => '3 rue de l\'église',
                'city' => 'Ostheim',
                'postalCode' => '68150',
                'country' => 'FR',
            ],
        ], $context->toArray());
    }

    /**
     * The accents stay as accents and the slashes stay as slashes: the base64
     * of this document is what gets sealed, so any re-encoding between here
     * and the form breaks the payment.
     */
    public function test_the_json_is_utf8_with_nothing_escaped(): void
    {
        $context = OrderContext::for(
            BillingAddress::create('3 rue de l\'église', 'Ostheim', '68150', 'FR')
                ->with(['firstName' => 'Jérémy', 'email' => 'jerem68@example.com']),
        );

        $json = $context->toJson();

        $this->assertStringContainsString('"Jérémy"', $json);
        $this->assertStringNotContainsString('\u00e9', $json);
        $this->assertSame($json, base64_decode($context->toField(), true));
    }

    public function test_a_context_survives_a_round_trip(): void
    {
        $context = OrderContext::for(
            BillingAddress::create('1 rue de Test', 'Paris', '75001', 'FR'),
            ShippingAddress::create('2 rue de Test', 'Lyon', '69001', 'FR')
                ->with(['shipIndicator' => ShipIndicator::DifferentAddress->value, 'deliveryTimeframe' => DeliveryTimeframe::TwoDay->value]),
            ShoppingCart::of(
                CartItem::create('T-shirt', Amount::fromMinor(2499, 'EUR'), 2),
                CartItem::create('Mug', Amount::fromMinor(990, 'EUR')),
            ),
            ClientInformation::guest('ada@example.com'),
        );

        $decoded = OrderContext::decode($context->toField());

        $this->assertSame([
            'billing' => ['addressLine1' => '1 rue de Test', 'city' => 'Paris', 'postalCode' => '75001', 'country' => 'FR'],
            'shipping' => [
                'addressLine1' => '2 rue de Test',
                'city' => 'Lyon',
                'postalCode' => '69001',
                'country' => 'FR',
                'shipIndicator' => 'different_address',
                'deliveryTimeframe' => 'two_day',
            ],
            'shoppingCart' => [
                'shoppingCartItems' => [
                    ['unitPrice' => 24.99, 'quantity' => 2, 'name' => 'T-shirt'],
                    ['unitPrice' => 9.9, 'quantity' => 1, 'name' => 'Mug'],
                ],
            ],
            'client' => ['email' => 'ada@example.com', 'authenticationMethod' => 'guest'],
        ], $decoded);
    }

    public function test_the_cart_price_is_a_number_and_not_the_amount_format(): void
    {
        // The currency lives on the order; a cart line carries a bare number.
        $item = CartItem::create('T-shirt', Amount::fromMinor(2499, 'EUR'), 3);

        $this->assertSame(['unitPrice' => 24.99, 'quantity' => 3, 'name' => 'T-shirt'], $item->toArray());
    }

    public function test_a_price_in_a_currency_without_decimals_stays_whole(): void
    {
        $item = CartItem::create('Mug', Amount::fromMinor(990, 'JPY'));

        $this->assertSame(990, $item->toArray()['unitPrice']);
    }

    /**
     * The documentation forbids sending an empty string for a value that is
     * not there, and an application writing `$customer->phone ?? ''` is
     * describing a value that is not there.
     */
    public function test_empty_values_are_dropped_rather_than_sent(): void
    {
        $billing = BillingAddress::create('1 rue de Test', 'Paris', '75001', 'FR')
            ->with(['phone' => '', 'email' => null, 'firstName' => 'Ada']);

        $this->assertFalse($billing->has('phone'));
        $this->assertFalse($billing->has('email'));
        $this->assertTrue($billing->has('firstName'));
    }

    public function test_setting_an_attribute_to_null_removes_it(): void
    {
        $billing = BillingAddress::create('1 rue de Test', 'Paris', '75001', 'FR')
            ->with(['firstName' => 'Ada'])
            ->with(['firstName' => null]);

        $this->assertFalse($billing->has('firstName'));
    }

    public function test_an_absent_object_is_left_out_of_the_document(): void
    {
        $context = OrderContext::for(
            BillingAddress::create('1 rue de Test', 'Paris', '75001', 'FR'),
            null,
            ShoppingCart::of(),
            ClientInformation::create(),
        );

        $this->assertSame(['billing'], array_keys($context->toArray()));
    }

    public function test_an_attribute_the_platform_does_not_document_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('"adressLine1" is not an attribute of this object');

        BillingAddress::create('1 rue de Test', 'Paris', '75001', 'FR')->with(['adressLine1' => 'typo']);
    }

    public function test_the_error_lists_what_the_object_does_accept(): void
    {
        try {
            ClientInformation::create()->with(['dateOfBirth' => '1987-03-27']);
            $this->fail('an undocumented attribute should have been refused');
        } catch (InvalidField $e) {
            $this->assertStringContainsString('birthdate', $e->getMessage());
        }
    }

    public function test_a_mandatory_attribute_cannot_be_empty(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('"city" is required');

        BillingAddress::create('1 rue de Test', '', '75001', 'FR');
    }

    public function test_the_state_or_province_is_carried_when_the_country_has_one(): void
    {
        $billing = BillingAddress::create('1 Infinite Loop', 'Cupertino', '95014', 'US', 'CA');

        $this->assertSame('CA', $billing->toArray()['stateOrProvince']);
    }

    /**
     * `matchBillingAddress` is one of the signals an issuer weighs: an order
     * delivered to the address that is paying for it is the least suspicious
     * thing there is.
     */
    public function test_shipping_to_the_billing_address_says_so(): void
    {
        $billing = BillingAddress::create('1 rue de Test', 'Paris', '75001', 'FR')
            ->with(['firstName' => 'Ada', 'mobilePhone' => '+33612345678']);

        $shipping = ShippingAddress::sameAsBilling($billing);

        $this->assertTrue($shipping->toArray()['matchBillingAddress']);
        $this->assertSame('billing_address', $shipping->toArray()['shipIndicator']);
        $this->assertSame('1 rue de Test', $shipping->toArray()['addressLine1']);
        $this->assertSame('Ada', $shipping->toArray()['firstName']);

        // The shipping object has no mobilePhone attribute, so copying one
        // across would have been refused.
        $this->assertFalse($shipping->has('mobilePhone'));
    }

    public function test_a_guest_says_it_was_a_guest(): void
    {
        $this->assertSame(
            AuthenticationMethod::Guest->value,
            ClientInformation::guest()->toArray()['authenticationMethod'],
        );
    }

    public function test_the_context_objects_are_immutable(): void
    {
        $billing = BillingAddress::create('1 rue de Test', 'Paris', '75001', 'FR');
        $withName = $billing->with(['firstName' => 'Ada']);

        $this->assertFalse($billing->has('firstName'));
        $this->assertTrue($withName->has('firstName'));
    }

    public function test_it_reads_a_document_back_and_says_when_it_cannot(): void
    {
        $this->assertNull(OrderContext::decode('not base64 at all !!'));
        $this->assertNull(OrderContext::decode(base64_encode('{not json')));
        $this->assertNull(OrderContext::decode(base64_encode('"a string"')));
        $this->assertSame(['a' => 1], OrderContext::decode(base64_encode('{"a":1}')));
    }
}
