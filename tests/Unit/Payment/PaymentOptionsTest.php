<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Payment\AlternativePaymentMethod;
use Piston\Monetico\Payment\PaymentOptions;
use Piston\Monetico\Payment\ThreeDSecureChallenge;

final class PaymentOptionsTest extends TestCase
{
    public function test_by_default_there_are_no_option_fields(): void
    {
        $this->assertSame([], PaymentOptions::none()->fields());
    }

    public function test_it_is_immutable(): void
    {
        $none = PaymentOptions::none();
        $iframe = $none->inIframe();

        $this->assertSame([], $none->fields());
        $this->assertSame(['mode_affichage' => 'iframe'], $iframe->fields());
    }

    public function test_it_sets_the_three_d_secure_preference(): void
    {
        $options = PaymentOptions::none()->withThreeDSecureChallenge(ThreeDSecureChallenge::NoChallengeRiskAnalysis);

        $this->assertSame(['ThreeDSecureChallenge' => 'no_challenge_requested_risk_analysis'], $options->fields());
    }

    public function test_it_can_disable_three_d_secure(): void
    {
        $this->assertSame(['3dsdebrayable' => '1'], PaymentOptions::none()->withoutThreeDSecure()->fields());
    }

    public function test_it_sets_what_appears_on_the_bank_statement(): void
    {
        $options = PaymentOptions::none()->asStatement('MyShop', 'Strasbourg\67000\FRA');

        $this->assertSame([
            'libelleMonetique' => 'MyShop',
            'libelleMonetiqueLocalite' => 'Strasbourg\67000\FRA',
        ], $options->fields());
    }

    public function test_the_locality_may_leave_the_postcode_out(): void
    {
        $options = PaymentOptions::none()->asStatement('MyShop', 'Strasbourg\\\\FRA');

        $this->assertSame('Strasbourg\\\\FRA', $options->fields()['libelleMonetiqueLocalite']);
    }

    public function test_a_statement_label_the_platform_would_refuse_is_refused_here(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('libelleMonetique');

        PaymentOptions::none()->asStatement('My Shop & Co');
    }

    public function test_a_locality_in_the_wrong_shape_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('city\zip\country');

        PaymentOptions::none()->asStatement('MyShop', 'Strasbourg, 67000, France');
    }

    public function test_it_hides_alternative_payment_methods(): void
    {
        $options = PaymentOptions::none()->withoutPaymentMethods(
            AlternativePaymentMethod::PayPal,
            AlternativePaymentMethod::ThreeTimesCard,
        );

        $this->assertSame(['desactivemoyenpaiement' => 'paypal,3xcb'], $options->fields());
    }

    public function test_it_can_go_straight_to_a_partner(): void
    {
        $this->assertSame(
            ['protocole' => 'lyfpay'],
            PaymentOptions::none()->through(AlternativePaymentMethod::LyfPay)->fields(),
        );
    }

    public function test_it_sets_a_card_alias_and_whether_to_ask_for_the_card_again(): void
    {
        $this->assertSame(
            ['aliascb' => 'customer42', 'forcesaisiecb' => '0'],
            PaymentOptions::none()->withCardAlias('customer42')->fields(),
        );

        $this->assertSame(
            ['aliascb' => 'customer42', 'forcesaisiecb' => '1'],
            PaymentOptions::none()->withCardAlias('customer42', forceEntry: true)->fields(),
        );
    }

    public function test_an_impossible_alias_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('aliascb');

        PaymentOptions::none()->withCardAlias('customer 42');
    }

    public function test_it_sets_the_pre_authorisation_file_number(): void
    {
        $this->assertSame(
            ['numero_dossier' => '20150901PRE1'],
            PaymentOptions::none()->withPreAuthorisationFile('20150901PRE1')->fields(),
        );
    }

    public function test_a_file_number_that_is_too_long_is_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('numero_dossier');

        PaymentOptions::none()->withPreAuthorisationFile('20150901PRE123');
    }

    /**
     * The escape hatch, for a field Monetico documents and this class does
     * not have a method for yet.
     */
    public function test_an_unknown_option_field_can_be_passed_through(): void
    {
        $options = new PaymentOptions(['someNewFieldFromMonetico' => 'value']);

        $this->assertSame(['someNewFieldFromMonetico' => 'value'], $options->fields());
    }

    public function test_the_escape_hatch_cannot_overwrite_the_order_itself(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('"montant" belongs to the order itself');

        new PaymentOptions(['montant' => '1EUR']);
    }

    public function test_options_accumulate(): void
    {
        $options = PaymentOptions::none()
            ->inIframe()
            ->withThreeDSecureChallenge(ThreeDSecureChallenge::ChallengePreferred)
            ->asStatement('MyShop');

        $this->assertSame([
            'mode_affichage' => 'iframe',
            'ThreeDSecureChallenge' => 'challenge_preferred',
            'libelleMonetique' => 'MyShop',
        ], $options->fields());
    }
}
