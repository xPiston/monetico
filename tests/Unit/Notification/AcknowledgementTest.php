<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Notification;

use PHPUnit\Framework\TestCase;
use Piston\Monetico\Notification\Acknowledgement;

final class AcknowledgementTest extends TestCase
{
    public function test_an_accepted_seal_answers_cdr_zero(): void
    {
        $this->assertSame("version=2\ncdr=0\n", Acknowledgement::accepted()->body());
    }

    public function test_a_rejected_seal_answers_cdr_one(): void
    {
        $this->assertSame("version=2\ncdr=1\n", Acknowledgement::rejected()->body());
    }

    public function test_the_separator_is_a_line_feed_and_there_is_one_at_the_end(): void
    {
        $body = Acknowledgement::accepted()->body();

        $this->assertStringNotContainsString("\r", $body);
        $this->assertStringEndsWith("\n", $body);
        $this->assertSame(['version=2', 'cdr=0', ''], explode("\n", $body));
    }

    /**
     * The whole decision in one call, and the only input it takes: the
     * payment's own result has no say in it.
     */
    public function test_it_is_decided_by_the_seal_and_nothing_else(): void
    {
        $this->assertSame(Acknowledgement::accepted()->body(), Acknowledgement::for(true)->body());
        $this->assertSame(Acknowledgement::rejected()->body(), Acknowledgement::for(false)->body());
    }

    public function test_it_is_plain_text(): void
    {
        $this->assertSame('text/plain', Acknowledgement::accepted()->contentType());
    }

    public function test_it_can_be_cast_to_its_body(): void
    {
        $this->assertSame("version=2\ncdr=0\n", (string) Acknowledgement::accepted());
    }

    public function test_it_says_which_one_it_is(): void
    {
        $this->assertTrue(Acknowledgement::accepted()->isAcceptance());
        $this->assertFalse(Acknowledgement::rejected()->isAcceptance());
    }
}
