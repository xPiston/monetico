<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Piston\Monetico\Exception\ServiceFailed;
use Piston\Monetico\Service\ServiceReply;

final class ServiceReplyTest extends TestCase
{
    public function test_it_reads_the_documented_capture_reply(): void
    {
        $reply = ServiceReply::parse("version=1.0\nreference=000000000145\ncdr=1\nlib=paiement accepte\naut=123456\n");

        $this->assertSame(1, $reply->returnCode());
        $this->assertSame('paiement accepte', $reply->label());
        $this->assertSame('1.0', $reply->version());
        $this->assertSame('000000000145', $reply->get('reference'));
        $this->assertSame('123456', $reply->get('aut'));
    }

    public function test_it_reads_a_negative_return_code(): void
    {
        $reply = ServiceReply::parse("version=1.0\nreference=000000000145\ncdr=-31\nlib=signature non valide");

        $this->assertSame(-31, $reply->returnCode());
    }

    public function test_it_tolerates_windows_line_endings(): void
    {
        $reply = ServiceReply::parse("version=1.0\r\ncdr=0\r\nlib=recredit effectue\r\n");

        $this->assertSame(0, $reply->returnCode());
        $this->assertSame('recredit effectue', $reply->label());
    }

    public function test_it_tolerates_surrounding_whitespace(): void
    {
        $reply = ServiceReply::parse("\n  cdr = 1 \n lib = paiement accepte \n\n");

        $this->assertSame(1, $reply->returnCode());
        $this->assertSame('paiement accepte', $reply->label());
    }

    public function test_a_value_containing_an_equals_sign_keeps_it(): void
    {
        $reply = ServiceReply::parse("cdr=1\nlib=a=b=c");

        $this->assertSame('a=b=c', $reply->label());
    }

    /** A field nobody has mapped yet is still readable. */
    public function test_it_keeps_every_field(): void
    {
        $reply = ServiceReply::parse("cdr=1\nsomethingNew=42");

        $this->assertSame('42', $reply->get('somethingNew'));
        $this->assertArrayHasKey('somethingNew', $reply->fields());
    }

    public function test_an_absent_field_is_null(): void
    {
        $reply = ServiceReply::parse("cdr=1\naut=");

        $this->assertNull($reply->get('aut'));
        $this->assertNull($reply->get('nothing'));
        $this->assertSame('', $reply->label());
    }

    /**
     * No `cdr` means this is not a service reply at all - an HTML error page,
     * a proxy notice, an empty body. Reading it as "cdr = 0" would turn a
     * network problem into a successful refund.
     */
    public function test_a_body_without_a_return_code_is_not_a_reply(): void
    {
        $this->expectException(ServiceFailed::class);
        $this->expectExceptionMessage('not a "key=value" reply');

        ServiceReply::parse('<html><body>Service Unavailable</body></html>');
    }

    public function test_an_empty_body_is_not_a_reply(): void
    {
        $this->expectException(ServiceFailed::class);

        ServiceReply::parse('');
    }

    public function test_a_very_long_body_is_cut_short_in_the_message(): void
    {
        try {
            ServiceReply::parse(str_repeat('x', 1000));
            $this->fail('a body with no return code should have been refused');
        } catch (ServiceFailed $e) {
            $this->assertLessThan(400, strlen($e->getMessage()));
            $this->assertStringEndsWith('...', $e->getMessage());
        }
    }
}
