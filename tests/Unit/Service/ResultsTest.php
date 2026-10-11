<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Service\CaptureOutcome;
use Piston\Monetico\Service\CaptureReason;
use Piston\Monetico\Service\CaptureResult;
use Piston\Monetico\Service\RefundError;
use Piston\Monetico\Service\RefundResult;
use Piston\Monetico\Service\ServiceReply;

final class ResultsTest extends TestCase
{
    public function test_a_captured_payment(): void
    {
        $result = CaptureResult::from(ServiceReply::parse(
            "version=1.0\nreference=000000000145\ncdr=1\nlib=paiement accepte\naut=123456",
        ));

        $this->assertTrue($result->isAccepted());
        $this->assertSame(CaptureOutcome::Accepted, $result->outcome);
        $this->assertSame(CaptureReason::PaymentAccepted, $result->reason);
        $this->assertSame('123456', $result->authorisationNumber());
        $this->assertSame('000000000145', $result->reference());
        $this->assertSame('paiement accepte', $result->label());
        $this->assertFalse($result->needsPhoneCall());
        $this->assertFalse($result->isRetryable());
    }

    public function test_a_cancelled_order_is_an_accepted_capture_request(): void
    {
        // The cancellation goes through the capture service, and "accepted"
        // there means the request was carried out, not that money moved.
        $result = CaptureResult::from(ServiceReply::parse("version=1.0\ncdr=1\nlib=commande annulee\naut=123456"));

        $this->assertTrue($result->isAccepted());
        $this->assertSame(CaptureReason::OrderCancelled, $result->reason);
    }

    public function test_a_refused_capture(): void
    {
        $result = CaptureResult::from(ServiceReply::parse("version=1.0\ncdr=0\nlib=autorisation refusee"));

        $this->assertFalse($result->isAccepted());
        $this->assertSame(CaptureOutcome::Refused, $result->outcome);
        $this->assertSame(CaptureReason::AuthorisationRefused, $result->reason);
        $this->assertFalse($result->isRetryable(), 'a refusal is an answer, not a failure to retry');
    }

    public function test_an_authorisation_that_needs_a_phone_call(): void
    {
        $result = CaptureResult::from(ServiceReply::parse(
            "version=1.0\nreference=000000000145\ncdr=0\nlib=autorisation refusee\nphonie=oui",
        ));

        $this->assertTrue($result->needsPhoneCall());
    }

    public function test_an_error_is_not_a_refusal(): void
    {
        $result = CaptureResult::from(ServiceReply::parse("version=1.0\ncdr=-1\nlib=commercant non identifie"));

        $this->assertSame(CaptureOutcome::Error, $result->outcome);
        $this->assertSame(CaptureReason::MerchantNotIdentified, $result->reason);
    }

    #[DataProvider('retryableCaptureLabels')]
    public function test_the_errors_the_documentation_says_to_retry(string $label): void
    {
        $result = CaptureResult::from(ServiceReply::parse("cdr=-1\nlib=".$label));

        $this->assertTrue($result->isRetryable());
    }

    /** @return iterable<string, array{string}> */
    public static function retryableCaptureLabels(): iterable
    {
        yield 'in progress' => ['traitement en cours'];
        yield 'another in progress' => ['autre traitement en cours'];
        yield 'maintenance' => ['indisponibilite temporaire du service'];
        yield 'technical' => ['probleme technique'];
    }

    /**
     * `lib` is prose, and the platform's troubleshooting section quotes it in
     * English where its reference table has French. The return code is the
     * contract; both spellings are read.
     */
    public function test_the_english_wording_from_the_troubleshooting_section_is_recognised(): void
    {
        $this->assertSame(
            CaptureReason::InvalidSeal,
            CaptureResult::from(ServiceReply::parse("cdr=-1\nlib=signature not valid"))->reason,
        );

        $this->assertSame(
            CaptureReason::MerchantNotIdentified,
            CaptureResult::from(ServiceReply::parse("cdr=-1\nlib=merchant not identified"))->reason,
        );
    }

    public function test_a_wording_nobody_has_seen_leaves_the_reason_unread_and_the_outcome_intact(): void
    {
        $result = CaptureResult::from(ServiceReply::parse("cdr=0\nlib=quelque chose de nouveau"));

        $this->assertSame(CaptureOutcome::Refused, $result->outcome);
        $this->assertNull($result->reason);
        $this->assertSame('quelque chose de nouveau', $result->label());
    }

    public function test_a_return_code_nobody_has_seen_is_an_error_and_not_a_success(): void
    {
        $this->assertSame(CaptureOutcome::Error, CaptureOutcome::fromReturnCode(42));
        $this->assertFalse(CaptureResult::from(ServiceReply::parse("cdr=42\nlib=?"))->isAccepted());
    }

    /**
     * Zero means refused in a capture reply and completed in a refund reply.
     * The two classes exist so that nothing has to remember which.
     */
    public function test_zero_is_success_for_a_refund_and_a_refusal_for_a_capture(): void
    {
        $reply = ServiceReply::parse("version=1.0\ncdr=0\nlib=recredit effectue");

        $this->assertTrue(RefundResult::from($reply)->isCompleted());
        $this->assertFalse(CaptureResult::from($reply)->isAccepted());
    }

    public function test_a_completed_refund(): void
    {
        $result = RefundResult::from(ServiceReply::parse(
            "version=1.0\nreference=000000000145\ncdr=0\nlib=recredit effectue",
        ));

        $this->assertTrue($result->isCompleted());
        $this->assertNull($result->error);
        $this->assertSame('000000000145', $result->reference());
        $this->assertFalse($result->isRetryable());
        $this->assertFalse($result->isAlreadyDone());
    }

    public function test_a_pre_authorisation_refund_echoes_its_amount(): void
    {
        $result = RefundResult::from(ServiceReply::parse(
            "version=1.0\ncdr=0\nlib=recredit effectue\naut=353683\nmontant_recredit=1EUR",
        ));

        $this->assertSame('1EUR', $result->refundedAmount()?->toMonetico());
        $this->assertSame('353683', $result->authorisationNumber());
    }

    public function test_an_amount_it_cannot_read_does_not_break_the_result(): void
    {
        $result = RefundResult::from(ServiceReply::parse("cdr=0\nmontant_recredit=unknown"));

        $this->assertNull($result->refundedAmount());
        $this->assertTrue($result->isCompleted());
    }

    public function test_a_refund_with_no_amount_in_its_reply(): void
    {
        $this->assertNull(RefundResult::from(ServiceReply::parse('cdr=0'))->refundedAmount());
    }

    #[DataProvider('refundErrors')]
    public function test_it_names_every_documented_refund_error(int $code, RefundError $expected): void
    {
        $result = RefundResult::from(ServiceReply::parse('cdr='.$code));

        $this->assertFalse($result->isCompleted());
        $this->assertSame($expected, $result->error);
    }

    /** @return iterable<string, array{int, RefundError}> */
    public static function refundErrors(): iterable
    {
        yield 'refused' => [-1, RefundError::Refused];
        yield 'merchant not identified' => [-30, RefundError::MerchantNotIdentified];
        yield 'invalid seal' => [-31, RefundError::InvalidSeal];
        yield 'refunds not enabled' => [-32, RefundError::NotAuthorised];
        yield 'expired' => [-33, RefundError::RequestExpired];
        yield 'wrong amount' => [-34, RefundError::WrongRefundAmount];
        yield 'amounts out of step' => [-35, RefundError::AmountsOutOfStep];
        yield 'daily quota' => [-36, RefundError::TooManyRefunds];
        yield 'order not found' => [-37, RefundError::OrderNotFound];
        yield 'not paid yet' => [-38, RefundError::OrderNotPaid];
        yield 'technical' => [-41, RefundError::TechnicalProblem];
        yield 'already fully refunded' => [-46, RefundError::AlreadyFullyRefunded];
        yield 'both or neither' => [-50, RefundError::AuthorisationAndRemittanceTogether];
        yield 'no global refund' => [-51, RefundError::GlobalRefundNotAllowed];
        yield 'wrong already-refunded amount' => [-52, RefundError::WrongAlreadyRefundedAmount];
    }

    /**
     * A refund that failed because the money is already back is a job that has
     * done its work. Retrying it all night is the alternative.
     */
    public function test_a_refund_that_was_already_done_says_so(): void
    {
        $result = RefundResult::from(ServiceReply::parse("cdr=-46\nlib=la commande est deja entierement recreditee"));

        $this->assertTrue($result->isAlreadyDone());
        $this->assertFalse($result->isRetryable());
    }

    public function test_the_two_refund_errors_worth_retrying(): void
    {
        $this->assertTrue(RefundResult::from(ServiceReply::parse('cdr=-41'))->isRetryable());
        $this->assertTrue(RefundResult::from(ServiceReply::parse('cdr=-44'))->isRetryable());
        $this->assertFalse(RefundResult::from(ServiceReply::parse('cdr=-34'))->isRetryable());
    }

    public function test_a_refund_error_nobody_has_seen_is_still_a_failure(): void
    {
        $result = RefundResult::from(ServiceReply::parse("cdr=-99\nlib=?"));

        $this->assertFalse($result->isCompleted());
        $this->assertNull($result->error);
        $this->assertFalse($result->isRetryable());
    }
}
