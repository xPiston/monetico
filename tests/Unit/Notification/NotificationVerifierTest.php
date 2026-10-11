<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Notification;

use PHPUnit\Framework\TestCase;
use Piston\Monetico\Configuration\Credentials;
use Piston\Monetico\Exception\SealMismatch;
use Piston\Monetico\Notification\NotificationVerifier;
use Piston\Monetico\Security\LegacyPositionalSeal;
use Piston\Monetico\Security\SealVerifier;
use Piston\Monetico\Tests\Support\FakePlatform;
use Piston\Monetico\Tests\Support\Fixtures;

final class NotificationVerifierTest extends TestCase
{
    public function test_it_accepts_a_notification_sealed_with_the_merchant_key(): void
    {
        $post = FakePlatform::for(Fixtures::credentials())->acceptedPayment('REF001', '62.73EUR');

        $notification = $this->verifier()->verify($post);

        $this->assertTrue($notification->isAccepted());
        $this->assertSame('alphabetical', $notification->sealedWith()?->name());
    }

    /**
     * The other half of the pair above, and the reason either test means
     * anything: a verifier that accepted everything would pass the first one.
     */
    public function test_it_refuses_a_notification_whose_amount_was_changed(): void
    {
        $post = FakePlatform::for(Fixtures::credentials())->acceptedPayment('REF001', '62.73EUR');
        $post['montant'] = '6273.00EUR';

        $this->expectException(SealMismatch::class);
        $this->expectExceptionMessage('not trustworthy');

        $this->verifier()->verify($post);
    }

    public function test_it_refuses_a_notification_with_a_field_added_after_sealing(): void
    {
        $post = FakePlatform::for(Fixtures::credentials())->acceptedPayment('REF001', '62.73EUR');
        $post['montantech'] = '10EUR';

        $this->expectException(SealMismatch::class);

        $this->verifier()->verify($post);
    }

    public function test_it_refuses_a_notification_with_a_field_removed_after_sealing(): void
    {
        $post = FakePlatform::for(Fixtures::credentials())->acceptedPayment('REF001', '62.73EUR');
        unset($post['texte-libre']);

        $this->expectException(SealMismatch::class);

        $this->verifier()->verify($post);
    }

    /**
     * The headline case. Monetico adds a field with a random name and random
     * content to *every* sandbox notification, and says why: to guarantee that
     * merchants handle parameters they have never seen. Any implementation
     * that recomputes the seal from a list of known field names fails here -
     * in the sandbox, before a shop ever goes live, with a seal error that
     * looks like a key problem.
     */
    public function test_a_field_this_library_has_never_heard_of_does_not_break_verification(): void
    {
        $platform = FakePlatform::for(Fixtures::credentials());
        $post = $platform->withRandomField(
            $platform->acceptedPayment('REF001', '62.73EUR'),
            'aRandomFieldName',
            'f8a3c1',
        );

        $notification = $this->verifier()->verify($post);

        $this->assertTrue($notification->isAccepted());
        $this->assertSame('f8a3c1', $notification->field('aRandomFieldName'));
    }

    public function test_it_accepts_a_notification_sealed_the_way_orders_were_before_the_migration(): void
    {
        $post = FakePlatform::legacy(Fixtures::credentials())->acceptedPayment('REF001', '62.73EUR');

        $notification = $this->verifier()->verify($post);

        $this->assertSame('legacy-positional', $notification->sealedWith()?->name());
    }

    public function test_a_shop_can_insist_on_the_current_algorithm_only(): void
    {
        $post = FakePlatform::legacy(Fixtures::credentials())->acceptedPayment('REF001', '62.73EUR');

        $verifier = new NotificationVerifier(Fixtures::credentials(), SealVerifier::strict());

        $this->expectException(SealMismatch::class);

        $verifier->verify($post);
    }

    public function test_it_refuses_a_notification_for_another_terminal(): void
    {
        $other = Credentials::fromStrings('7654321', Fixtures::KEY, Fixtures::COMPANY);
        $post = FakePlatform::for($other)->acceptedPayment('REF001', '62.73EUR');

        $this->expectException(SealMismatch::class);
        $this->expectExceptionMessage('This notification is for terminal 7654321, not 1234567');

        $this->verifier()->verify($post);
    }

    public function test_it_refuses_a_notification_with_no_terminal_at_all(): void
    {
        $this->expectException(SealMismatch::class);
        $this->expectExceptionMessage('(none)');

        $this->verifier()->verify(['montant' => '10EUR', 'MAC' => str_repeat('a', 40)]);
    }

    public function test_it_refuses_a_notification_with_no_seal(): void
    {
        $post = FakePlatform::for(Fixtures::credentials())->acceptedPayment('REF001', '62.73EUR');
        unset($post['MAC']);

        $this->expectException(SealMismatch::class);
        $this->expectExceptionMessage('carries no MAC field');

        $this->verifier()->verify($post);
    }

    public function test_it_refuses_a_seal_that_is_not_forty_hexadecimal_characters(): void
    {
        $post = FakePlatform::for(Fixtures::credentials())->acceptedPayment('REF001', '62.73EUR');
        $post['MAC'] = 'nonsense';

        $this->expectException(SealMismatch::class);
        $this->expectExceptionMessage('carries no MAC field');

        $this->verifier()->verify($post);
    }

    public function test_a_seal_sent_in_upper_case_is_accepted(): void
    {
        $post = FakePlatform::for(Fixtures::credentials())->acceptedPayment('REF001', '62.73EUR');
        $post['MAC'] = strtoupper($post['MAC']);

        $this->assertTrue($this->verifier()->verify($post)->isAccepted());
    }

    public function test_a_refused_payment_with_a_valid_seal_verifies(): void
    {
        // Verification is about authenticity, not about the payment's result.
        $post = FakePlatform::for(Fixtures::credentials())->refusedPayment('REF001', '62.73EUR');

        $notification = $this->verifier()->verify($post);

        $this->assertFalse($notification->isAccepted());
        $this->assertNotNull($notification->sealedWith());
    }

    public function test_attempt_returns_null_instead_of_throwing(): void
    {
        $post = FakePlatform::for(Fixtures::credentials())->acceptedPayment('REF001', '62.73EUR');

        $this->assertNotNull($this->verifier()->attempt($post));

        $post['montant'] = '1EUR';

        $this->assertNull($this->verifier()->attempt($post));
    }

    public function test_the_legacy_algorithm_is_tried_after_the_current_one(): void
    {
        $verifier = new NotificationVerifier(
            Fixtures::credentials(),
            new SealVerifier(new LegacyPositionalSeal),
        );

        $post = FakePlatform::legacy(Fixtures::credentials())->acceptedPayment('REF001', '62.73EUR');

        $this->assertSame('legacy-positional', $verifier->verify($post)->sealedWith()?->name());
    }

    private function verifier(): NotificationVerifier
    {
        return new NotificationVerifier(Fixtures::credentials());
    }
}
