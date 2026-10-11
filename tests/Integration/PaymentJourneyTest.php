<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Configuration\Environment;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\Language;
use Piston\Monetico\Field\Reference;
use Piston\Monetico\Monetico;
use Piston\Monetico\Notification\Acknowledgement;
use Piston\Monetico\Payment\Context\BillingAddress;
use Piston\Monetico\Payment\Context\CartItem;
use Piston\Monetico\Payment\Context\ClientInformation;
use Piston\Monetico\Payment\Context\OrderContext;
use Piston\Monetico\Payment\Context\ShoppingCart;
use Piston\Monetico\Payment\PaymentOptions;
use Piston\Monetico\Payment\PaymentRequest;
use Piston\Monetico\Payment\ThreeDSecureChallenge;
use Piston\Monetico\Service\CaptureRequest;
use Piston\Monetico\Service\RefundRequest;
use Piston\Monetico\Tests\Support\FakeHttpClient;
use Piston\Monetico\Tests\Support\FakePlatform;
use Piston\Monetico\Tests\Support\Fixtures;

/**
 * One order, from the form to the refund, with the four verbs in the order a
 * shop uses them.
 *
 * The parts are tested on their own elsewhere; what this checks is that they
 * agree with each other - that the reference and the amount a form was sealed
 * with are the ones a notification is compared against, and that the seal a
 * notification is verified with is the one the form's own key produces.
 */
final class PaymentJourneyTest extends TestCase
{
    public function test_a_payment_from_the_form_to_the_refund(): void
    {
        $monetico = Monetico::sandbox(Fixtures::TERMINAL, Fixtures::KEY, Fixtures::COMPANY);
        $reference = Reference::of('REF7896543');
        $amount = Amount::fromMinor(6273, 'EUR');

        // 1. The form the customer is sent to.
        $form = $monetico->form(new PaymentRequest(
            reference: $reference,
            amount: $amount,
            orderedAt: new DateTimeImmutable('2026-10-12 11:00:00', new DateTimeZone('Europe/Paris')),
            language: Language::French,
            context: OrderContext::for(
                BillingAddress::create('1 rue de Test', 'Paris', '75001', 'FR')->with(['firstName' => 'Ada', 'lastName' => 'Lovelace']),
                null,
                ShoppingCart::of(CartItem::create('T-shirt', Amount::fromMinor(2499, 'EUR'), 2)),
                ClientInformation::create('ada@example.com'),
            ),
            customerEmail: 'ada@example.com',
            freeText: 'Order REF7896543',
            successUrl: 'https://shop.test/merci',
            errorUrl: 'https://shop.test/echec',
            options: PaymentOptions::none()
                ->asStatement('MyShop')
                ->withThreeDSecureChallenge(ThreeDSecureChallenge::NoChallengeRiskAnalysis),
        ));

        $this->assertSame('https://p.monetico-services.com/test/paiement.cgi', $form->action());
        $this->assertSame('62.73EUR', $form->fields()['montant']);
        $this->assertSame('REF7896543', $form->fields()['reference']);
        $this->assertSame('12/10/2026:11:00:00', $form->fields()['date']);

        // The seal, recomputed here without using the library's own algorithm
        // object: sorted `name=value` pairs joined by `*`, hashed with the
        // 20 bytes of the key.
        $sealed = $form->fields();
        unset($sealed['MAC']);
        uksort($sealed, static fn (string $a, string $b): int => strcmp($a, $b));
        $pairs = [];

        foreach ($sealed as $name => $value) {
            $pairs[] = $name.'='.$value;
        }

        $this->assertSame(
            hash_hmac('sha1', implode('*', $pairs), (string) hex2bin(strtolower(Fixtures::KEY))),
            $form->seal(),
        );

        // The browser sees escaped values; the seal was computed over the raw
        // ones.
        $this->assertStringContainsString('name="MAC" value="'.$form->seal().'"', $form->toInputs());

        // 2. The notification the platform posts back.
        // In the sandbox an accepted payment says `payetest`, not `paiement` -
        // and the sandbox adds a field with a random name to every
        // notification on purpose.
        $platform = FakePlatform::for($monetico->credentials);
        $post = $platform->withRandomField(
            $platform->acceptedPayment($reference->value(), $amount->toMonetico(), ['code-retour' => 'payetest']),
            'aFieldFromTheFuture',
            '42',
        );

        $notification = $monetico->notification($post);

        $this->assertTrue($notification->isAccepted());
        $this->assertTrue($notification->matches($reference, $amount));
        $this->assertFalse($notification->looksLikeWrongEnvironment(Environment::Sandbox));
        $this->assertTrue($notification->looksLikeWrongEnvironment(Environment::Production));
        $this->assertSame('42', $notification->field('aFieldFromTheFuture'));
        $this->assertSame('010101', $notification->authorisationNumber());

        // 3. What the endpoint writes back, within fifteen seconds.
        $this->assertSame(
            "version=2\ncdr=0\n",
            Acknowledgement::for($notification->sealedWith() !== null)->body(),
        );

        // 4. Collecting the money, two days later.
        $http = FakeHttpClient::replying("version=1.0\nreference=REF7896543\ncdr=1\nlib=paiement accepte\naut=123456");
        $withTransport = new Monetico(
            $monetico->credentials,
            Environment::Sandbox,
            $http,
            new \Nyholm\Psr7\Factory\Psr17Factory,
            new \Nyholm\Psr7\Factory\Psr17Factory,
        );

        $capture = $withTransport->capture(CaptureRequest::of(
            reference: $reference,
            orderAmount: $amount,
            toCapture: $amount,
            orderedOn: new DateTimeImmutable('2026-10-12', new DateTimeZone('Europe/Paris')),
        ));

        $this->assertTrue($capture->isAccepted());
        $this->assertSame('123456', $capture->authorisationNumber());

        // 5. And sending it back when the customer returns the shirt.
        $http = FakeHttpClient::replying("version=1.0\nreference=REF7896543\ncdr=0\nlib=recredit effectue");
        $withTransport = new Monetico(
            $monetico->credentials,
            Environment::Sandbox,
            $http,
            new \Nyholm\Psr7\Factory\Psr17Factory,
            new \Nyholm\Psr7\Factory\Psr17Factory,
        );

        $refund = $withTransport->refund(RefundRequest::of(
            reference: $reference,
            orderAmount: $amount,
            toRefund: $amount,
            orderedOn: new DateTimeImmutable('2026-10-12', new DateTimeZone('Europe/Paris')),
            remittedOn: new DateTimeImmutable('2026-10-14', new DateTimeZone('Europe/Paris')),
            authorisationNumber: '123456',
        ));

        $this->assertTrue($refund->isCompleted());
    }

    /**
     * The same journey with the amount changed in flight, which is the only
     * reason any of the above is worth doing.
     */
    public function test_a_notification_that_was_tampered_with_is_refused_and_still_answered(): void
    {
        $monetico = Monetico::sandbox(Fixtures::TERMINAL, Fixtures::KEY, Fixtures::COMPANY);

        $post = FakePlatform::for($monetico->credentials)->acceptedPayment('REF7896543', '62.73EUR');
        $post['montant'] = '0.01EUR';

        $notification = $monetico->attemptNotification($post);
        $answer = Acknowledgement::for($notification !== null);

        $this->assertNull($notification);
        $this->assertSame("version=2\ncdr=1\n", $answer->body());
    }

    /**
     * A payment whose seal is perfectly valid but whose amount is not the
     * order's. The seal proves the message is authentic, not that it is about
     * what the shop asked for.
     */
    public function test_an_authentic_notification_for_the_wrong_amount_is_caught_by_the_shop(): void
    {
        $monetico = Monetico::sandbox(Fixtures::TERMINAL, Fixtures::KEY, Fixtures::COMPANY);

        $post = FakePlatform::for($monetico->credentials)->acceptedPayment('REF7896543', '1.00EUR');

        $notification = $monetico->notification($post);

        $this->assertTrue($notification->isAccepted(), 'the message is authentic');
        $this->assertFalse(
            $notification->matches(Reference::of('REF7896543'), Amount::fromMinor(6273, 'EUR')),
            'and it is not the order the shop is waiting for',
        );
    }

    public function test_the_production_form_goes_to_the_production_url(): void
    {
        $monetico = Monetico::production(Fixtures::TERMINAL, Fixtures::KEY, Fixtures::COMPANY);

        $form = $monetico->form(new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: new DateTimeImmutable('2026-10-12 11:00:00'),
        ));

        $this->assertSame('https://p.monetico-services.com/paiement.cgi', $form->action());
        $this->assertSame(Environment::Production, $monetico->environment);
    }

    /**
     * An instalment plan, and the notification that arrives for its third due
     * date months later - sealed the way the order was created, which by then
     * may be the older algorithm.
     */
    public function test_an_instalment_notification_months_later_still_verifies(): void
    {
        $monetico = Monetico::sandbox(Fixtures::TERMINAL, Fixtures::KEY, Fixtures::COMPANY);

        $post = FakePlatform::legacy($monetico->credentials)->acceptedPayment('REF001', '100.00EUR', [
            'code-retour' => 'paiement_pf3',
            'montantech' => '25.00EUR',
        ]);

        $notification = $monetico->notification($post);

        $this->assertTrue($notification->isAccepted());
        $this->assertSame(3, $notification->instalment());
        $this->assertSame('25.00EUR', $notification->instalmentAmount()?->toMonetico());
        $this->assertSame('legacy-positional', $notification->sealedWith()?->name());
    }
}
