<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Configuration\Environment;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\Reference;
use Piston\Monetico\Notification\AccountType;
use Piston\Monetico\Notification\Authentication;
use Piston\Monetico\Notification\AuthorisationRefusal;
use Piston\Monetico\Notification\CardBrand;
use Piston\Monetico\Notification\CardUsage;
use Piston\Monetico\Notification\Notification;
use Piston\Monetico\Notification\PaymentMethod;
use Piston\Monetico\Notification\RefusalReason;
use Piston\Monetico\Notification\ReturnCode;

final class NotificationTest extends TestCase
{
    /** The example payload from the documentation, already decoded by PHP. */
    private const ACCEPTED = [
        'TPE' => '1234567',
        'date' => '05/12/2006_a_11:55:23',
        'montant' => '62.75EUR',
        'reference' => 'ABERTYP00145',
        'MAC' => 'e4359a2c18d86cf2e4b0e646016c202e89947b04',
        'texte-libre' => 'LeTexteLibre',
        'code-retour' => 'paiement',
        'cvx' => 'oui',
        'vld' => '1208',
        'brand' => 'VI',
        'numauto' => '010101',
        'originecb' => 'FRA',
        'bincb' => '010101',
        'hpancb' => '74E94B03C22D786E0F2C2CADBFC1C00B004B7C45',
        'ipclient' => '127.0.0.1',
        'originetr' => 'FRA',
    ];

    public function test_it_reads_the_payment(): void
    {
        $notification = Notification::fromArray(self::ACCEPTED);

        $this->assertTrue($notification->isAccepted());
        $this->assertSame(ReturnCode::Accepted, $notification->returnCode());
        $this->assertSame('ABERTYP00145', $notification->reference()?->value());
        $this->assertSame('62.75EUR', $notification->amount()?->toMonetico());
        $this->assertSame('010101', $notification->authorisationNumber());
        $this->assertSame('LeTexteLibre', $notification->freeText());
        $this->assertSame('127.0.0.1', $notification->clientIp());
        $this->assertSame('FRA', $notification->transactionCountry());
        $this->assertSame('1234567', $notification->terminal()?->value());
        $this->assertSame('e4359a2c18d86cf2e4b0e646016c202e89947b04', $notification->seal()?->value());
        $this->assertSame('2006-12-05 11:55:23', $notification->occurredAt()?->format('Y-m-d H:i:s'));
    }

    public function test_it_reads_the_card(): void
    {
        $card = Notification::fromArray([
            ...self::ACCEPTED,
            'cbmasquee' => '12345678******12',
            'usage' => 'credit',
            'typecompte' => 'particulier',
            'ecard' => 'non',
        ])->card();

        $this->assertSame(CardBrand::Visa, $card->brand);
        $this->assertSame('12345678******12', $card->maskedNumber);
        $this->assertSame('010101', $card->bin);
        $this->assertSame('74E94B03C22D786E0F2C2CADBFC1C00B004B7C45', $card->fingerprint);
        $this->assertSame('FRA', $card->issuingCountry);
        $this->assertTrue($card->cryptogramEntered);
        $this->assertSame(CardUsage::Credit, $card->usage);
        $this->assertSame(AccountType::Personal, $card->accountType);
        $this->assertFalse($card->isVirtual);
    }

    public function test_it_reads_a_refusal(): void
    {
        $notification = Notification::fromArray([
            ...self::ACCEPTED,
            'code-retour' => 'Annulation',
            'numauto' => '',
            'motifrefus' => 'Refus',
            'motifrefusautorisation' => 'Refus temporaire',
        ]);

        $this->assertFalse($notification->isAccepted());
        $this->assertSame(ReturnCode::Refused, $notification->returnCode());
        $this->assertSame(RefusalReason::Refused, $notification->refusalReason());
        $this->assertSame(AuthorisationRefusal::Temporary, $notification->authorisationRefusal());
        $this->assertTrue($notification->authorisationRefusal()->isRetryable());
        $this->assertNull($notification->authorisationNumber());
    }

    public function test_it_reads_the_fraud_module_filters(): void
    {
        $notification = Notification::fromArray([
            ...self::ACCEPTED,
            'code-retour' => 'Annulation',
            'motifrefus' => 'filtrage',
            'filtragecause' => '4-',
            'filtragevaleur' => 'FRA-',
        ]);

        $hits = $notification->filterHits();

        $this->assertCount(1, $hits);
        $this->assertSame(4, $hits[0]->cause);
        $this->assertSame('FRA', $hits[0]->value);
        $this->assertFalse($hits[0]->informationOnly);
        $this->assertTrue($notification->refusalReason()?->isMerchantsOwnRule());
    }

    public function test_it_reads_a_split_payment_instalment(): void
    {
        $notification = Notification::fromArray([
            ...self::ACCEPTED,
            'code-retour' => 'paiement_pf3',
            'montantech' => '20EUR',
        ]);

        $this->assertTrue($notification->isAccepted());
        $this->assertSame(3, $notification->instalment());
        $this->assertSame('20EUR', $notification->instalmentAmount()?->toMonetico());
    }

    public function test_it_reads_a_pre_authorisation(): void
    {
        $notification = Notification::fromArray([
            ...self::ACCEPTED,
            'montant' => '',
            'montantestime' => '95.25EUR',
            'numero_dossier' => '20150901PRE1',
        ]);

        $this->assertNull($notification->amount());
        $this->assertSame('95.25EUR', $notification->estimatedAmount()?->toMonetico());
        $this->assertSame('20150901PRE1', $notification->fileNumber());
    }

    public function test_it_reads_a_meal_voucher(): void
    {
        $notification = Notification::fromArray([
            ...self::ACCEPTED,
            'brand' => 'CO',
            'issuer' => 'SODEXO',
            'soldecompte' => '95.25EUR',
        ]);

        $this->assertTrue($notification->card()->brand?->isMealVoucher());
        $this->assertSame('SODEXO', $notification->mealVoucherIssuer());
        $this->assertSame('95.25EUR', $notification->remainingBalance()?->toMonetico());
    }

    public function test_it_reads_the_express_payment_fields(): void
    {
        $card = Notification::fromArray([...self::ACCEPTED, 'cbenregistree' => '1'])->card();

        $this->assertTrue($card->savedUnderAlias);
    }

    public function test_it_reads_the_payment_method(): void
    {
        $this->assertSame(
            PaymentMethod::PayPal,
            Notification::fromArray([...self::ACCEPTED, 'modepaiement' => 'paypal'])->paymentMethod(),
        );
    }

    #[DataProvider('returnCodes')]
    public function test_it_knows_which_return_codes_are_a_payment(string $code, bool $accepted, ?int $instalment): void
    {
        $notification = Notification::fromArray([...self::ACCEPTED, 'code-retour' => $code]);

        $this->assertSame($accepted, $notification->isAccepted());
        $this->assertSame($instalment, $notification->instalment());
    }

    /** @return iterable<string, array{string, bool, int|null}> */
    public static function returnCodes(): iterable
    {
        yield 'production' => ['paiement', true, null];
        yield 'sandbox' => ['payetest', true, null];
        yield 'refused' => ['Annulation', false, null];
        yield 'second instalment' => ['paiement_pf2', true, 2];
        yield 'third instalment' => ['paiement_pf3', true, 3];
        yield 'fourth instalment' => ['paiement_pf4', true, 4];
        yield 'second instalment refused' => ['Annulation_pf2', false, 2];
        yield 'lower case' => ['annulation', false, null];
        yield 'unknown' => ['something_new', false, null];
        yield 'empty' => ['', false, null];
    }

    /**
     * Everything about reading a notification has to survive nonsense,
     * because the fifteen-second acknowledgement depends on the seal and not
     * on this library's vocabulary.
     */
    public function test_nothing_throws_on_a_payload_made_of_rubbish(): void
    {
        $notification = Notification::fromArray([
            'TPE' => 'not a terminal',
            'date' => 'yesterday',
            'montant' => 'free',
            'reference' => str_repeat('x', 500),
            'MAC' => 'nope',
            'code-retour' => 'who knows',
            'brand' => 'ZZ',
            'vld' => 'never',
            'usage' => 'maybe',
            'authentification' => 'not base64',
            'filtragecause' => 'abc-def',
            'montantech' => '??',
        ]);

        $this->assertNull($notification->terminal());
        $this->assertNull($notification->occurredAt());
        $this->assertNull($notification->amount());
        $this->assertNull($notification->reference());
        $this->assertNull($notification->seal());
        $this->assertNull($notification->returnCode());
        $this->assertFalse($notification->isAccepted());
        $this->assertNull($notification->card()->brand);
        $this->assertNull($notification->card()->expiresAt());
        $this->assertNull($notification->authentication());
        $this->assertSame([], $notification->filterHits());
        $this->assertNull($notification->instalmentAmount());
        $this->assertSame('who knows', $notification->rawReturnCode());
    }

    public function test_an_empty_payload_reads_as_nothing_rather_than_breaking(): void
    {
        $notification = Notification::fromArray([]);

        $this->assertSame([], $notification->fields());
        $this->assertFalse($notification->isAccepted());
        $this->assertNull($notification->field('anything'));
    }

    public function test_it_keeps_every_field_it_was_given(): void
    {
        $notification = Notification::fromArray([...self::ACCEPTED, 'aNewField' => 'value']);

        $this->assertSame('value', $notification->field('aNewField'));
        $this->assertArrayHasKey('aNewField', $notification->fields());
    }

    public function test_an_empty_field_reads_as_absent(): void
    {
        $notification = Notification::fromArray([...self::ACCEPTED, 'numauto' => '']);

        $this->assertNull($notification->authorisationNumber());
    }

    public function test_it_ignores_values_that_are_not_scalar(): void
    {
        // A nested array in the body cannot be part of a seal the platform
        // computed over strings.
        $notification = Notification::fromArray([...self::ACCEPTED, 'weird' => ['a' => 'b']]);

        $this->assertArrayNotHasKey('weird', $notification->fields());
    }

    /**
     * The order the shop remembers, against the payment that arrived. A valid
     * seal says the message is authentic; only this says it is about the right
     * order for the right money.
     */
    public function test_it_compares_itself_to_the_order_the_shop_stored(): void
    {
        $notification = Notification::fromArray(self::ACCEPTED);

        $this->assertTrue($notification->matches(Reference::of('ABERTYP00145'), Amount::fromMinor(6275, 'EUR')));
        $this->assertFalse($notification->matches(Reference::of('ABERTYP00146'), Amount::fromMinor(6275, 'EUR')));
        $this->assertFalse($notification->matches(Reference::of('ABERTYP00145'), Amount::fromMinor(6276, 'EUR')));
        $this->assertFalse($notification->matches(Reference::of('ABERTYP00145'), Amount::fromMinor(6275, 'USD')));
    }

    public function test_the_comparison_is_on_value_and_not_on_formatting(): void
    {
        $notification = Notification::fromArray([...self::ACCEPTED, 'montant' => '15.5EUR']);

        $this->assertTrue($notification->matches(Reference::of('ABERTYP00145'), Amount::fromMinor(1550, 'EUR')));
    }

    public function test_a_pre_authorisation_is_compared_on_its_estimated_amount(): void
    {
        $notification = Notification::fromArray([
            ...self::ACCEPTED,
            'montant' => '',
            'montantestime' => '62.75EUR',
        ]);

        $this->assertTrue($notification->matches(Reference::of('ABERTYP00145'), Amount::fromMinor(6275, 'EUR')));
    }

    public function test_payetest_in_production_is_an_anomaly_and_says_so(): void
    {
        $sandboxPayment = Notification::fromArray([...self::ACCEPTED, 'code-retour' => 'payetest']);
        $productionPayment = Notification::fromArray(self::ACCEPTED);

        $this->assertTrue($sandboxPayment->looksLikeWrongEnvironment(Environment::Production));
        $this->assertFalse($sandboxPayment->looksLikeWrongEnvironment(Environment::Sandbox));

        $this->assertTrue($productionPayment->looksLikeWrongEnvironment(Environment::Sandbox));
        $this->assertFalse($productionPayment->looksLikeWrongEnvironment(Environment::Production));
    }

    /**
     * PHP has already decoded the body by the time it reaches `$_POST`, so
     * the raw-body reader has to decode exactly once - not twice, and not
     * zero times.
     */
    public function test_it_decodes_a_raw_body_once(): void
    {
        $notification = Notification::fromRawBody(
            'TPE=1234567&date=05%2F12%2F2006%5Fa%5F11%3A55%3A23&texte-libre=100%2525+discount&montant=62%2E75EUR',
        );

        $this->assertSame('1234567', $notification->field('TPE'));
        $this->assertSame('05/12/2006_a_11:55:23', $notification->field('date'));
        $this->assertSame('62.75EUR', $notification->field('montant'));

        // `%2525` decoded once is `%25`, not `%`.
        $this->assertSame('100%25 discount', $notification->field('texte-libre'));
    }

    public function test_a_raw_body_with_an_empty_value_keeps_the_field(): void
    {
        $notification = Notification::fromRawBody('TPE=1234567&motifrefus=&veres=Y');

        $this->assertArrayHasKey('motifrefus', $notification->fields());
        $this->assertSame('', $notification->fields()['motifrefus']);
        $this->assertSame('Y', $notification->field('veres'));
    }

    public function test_a_raw_body_tolerates_a_field_without_a_value_separator(): void
    {
        $notification = Notification::fromRawBody('TPE=1234567&orphan&');

        $this->assertSame('', $notification->fields()['orphan']);
    }

    public function test_it_reads_the_three_d_secure_document(): void
    {
        $document = [
            'protocol' => '3DSecure',
            'status' => 'authenticated',
            'version' => '2.1.0',
            'liabilityShift' => 'Y',
            'details' => ['ARes' => 'Y', 'CRes' => 'Y'],
        ];

        $notification = Notification::fromArray([
            ...self::ACCEPTED,
            'authentification' => base64_encode((string) json_encode($document)),
        ]);

        $authentication = $notification->authentication();

        $this->assertInstanceOf(Authentication::class, $authentication);
        $this->assertSame('3DSecure', $authentication->protocol());
        $this->assertSame('authenticated', $authentication->status());
        $this->assertSame('2.1.0', $authentication->version());
        $this->assertTrue($authentication->liabilityShift());
        $this->assertSame(['ARes' => 'Y', 'CRes' => 'Y'], $authentication->details());
    }

    public function test_the_seal_algorithm_is_remembered_once_a_verifier_has_checked_it(): void
    {
        $notification = Notification::fromArray(self::ACCEPTED);

        $this->assertNull($notification->sealedWith());

        $checked = $notification->withSealAlgorithm(new \Piston\Monetico\Security\AlphabeticalSeal);

        $this->assertSame('alphabetical', $checked->sealedWith()?->name());
        $this->assertNull($notification->sealedWith(), 'the original must not change');
    }
}
