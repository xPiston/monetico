<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Field\Reference;

final class ReferenceTest extends TestCase
{
    public function test_it_keeps_the_reference_as_given(): void
    {
        $this->assertSame('REF7896543', Reference::of('REF7896543')->value());
        $this->assertSame('REF7896543', (string) Reference::of('REF7896543'));
    }

    public function test_it_accepts_printable_ascii_up_to_fifty_characters(): void
    {
        $this->assertSame('CMD-2026/0042 #7', Reference::of('CMD-2026/0042 #7')->value());
        $this->assertSame(str_repeat('a', 50), Reference::of(str_repeat('a', 50))->value());
    }

    #[DataProvider('invalidReferences')]
    public function test_it_refuses_what_the_payment_form_would_refuse(string $value): void
    {
        $this->expectException(InvalidField::class);

        Reference::of($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidReferences(): iterable
    {
        yield 'empty' => [''];
        yield 'fifty-one characters' => [str_repeat('a', 51)];
        yield 'a line feed' => ["REF\n001"];
        yield 'a carriage return' => ["REF\r001"];
        yield 'a tab' => ["REF\t001"];
        yield 'an accent' => ['RÉF001'];
    }

    /**
     * The trap: the payment form accepts a dash and the capture and refund
     * services do not. A reference like this one is payable and not
     * collectable.
     */
    public function test_it_knows_whether_the_capture_and_refund_services_will_take_it(): void
    {
        $this->assertTrue(Reference::of('REF7896543')->isServiceSafe());
        $this->assertFalse(Reference::of('CMD-2026-0042')->isServiceSafe());
        $this->assertFalse(Reference::of('order 42')->isServiceSafe());
    }

    public function test_it_knows_whether_the_bank_will_carry_it_to_the_statement(): void
    {
        $this->assertTrue(Reference::of('REF789654321')->fitsOnBankStatement());
        $this->assertFalse(Reference::of('REF7896543210')->fitsOnBankStatement());
        $this->assertFalse(Reference::of('REF-7896')->fitsOnBankStatement());
    }

    public function test_two_identical_references_are_the_same_order(): void
    {
        $this->assertTrue(Reference::of('REF001')->equals(Reference::of('REF001')));
        $this->assertFalse(Reference::of('REF001')->equals(Reference::of('ref001')));
    }
}
