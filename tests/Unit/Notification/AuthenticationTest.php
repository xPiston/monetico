<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Notification\Authentication;

final class AuthenticationTest extends TestCase
{
    public function test_it_reads_the_document(): void
    {
        $authentication = $this->of([
            'protocol' => '3DSecure',
            'status' => 'authenticated',
            'version' => '2.2.0',
            'liabilityShift' => true,
            'details' => ['ARes' => 'Y'],
        ]);

        $this->assertSame('3DSecure', $authentication->protocol());
        $this->assertSame('authenticated', $authentication->status());
        $this->assertSame('2.2.0', $authentication->version());
        $this->assertTrue($authentication->liabilityShift());
        $this->assertSame(['ARes' => 'Y'], $authentication->details());
    }

    /**
     * The one value worth storing with the order: it decides who pays for a
     * fraudulent payment months later, and a payment can be accepted with the
     * shift and accepted without it.
     */
    #[DataProvider('liabilityShiftValues')]
    public function test_it_reads_the_liability_shift_however_it_is_written(mixed $value, ?bool $expected): void
    {
        $this->assertSame($expected, $this->of(['liabilityShift' => $value])->liabilityShift());
    }

    /** @return iterable<string, array{mixed, bool|null}> */
    public static function liabilityShiftValues(): iterable
    {
        yield 'boolean true' => [true, true];
        yield 'boolean false' => [false, false];
        yield 'Y' => ['Y', true];
        yield 'yes' => ['yes', true];
        yield 'true' => ['true', true];
        yield 'one' => ['1', true];
        yield 'N' => ['N', false];
        yield 'empty string' => ['', false];
        yield 'a number' => [1, null];
        yield 'absent' => [null, null];
    }

    public function test_a_document_with_nothing_in_it_answers_null_to_everything(): void
    {
        $authentication = $this->of([]);

        $this->assertNull($authentication->protocol());
        $this->assertNull($authentication->status());
        $this->assertNull($authentication->version());
        $this->assertNull($authentication->liabilityShift());
        $this->assertSame([], $authentication->details());
        $this->assertSame([], $authentication->toArray());
    }

    public function test_details_that_are_not_an_object_read_as_none(): void
    {
        $this->assertSame([], $this->of(['details' => 'nonsense'])->details());
    }

    public function test_a_field_that_is_not_base64_is_null(): void
    {
        $this->assertNull(Authentication::fromField('not base64 at all !!'));
    }

    public function test_a_field_that_is_not_json_is_null(): void
    {
        $this->assertNull(Authentication::fromField(base64_encode('{broken')));
    }

    public function test_a_numeric_version_is_read_as_a_string(): void
    {
        $this->assertSame('2', $this->of(['version' => 2])->version());
    }

    /** @param array<string, mixed> $document */
    private function of(array $document): Authentication
    {
        $authentication = Authentication::fromField(base64_encode((string) json_encode($document)));

        $this->assertInstanceOf(Authentication::class, $authentication);

        return $authentication;
    }
}
