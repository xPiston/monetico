<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Piston\Monetico\Security\AlphabeticalSeal;
use Piston\Monetico\Tests\Support\Fixtures;

final class AlphabeticalSealTest extends TestCase
{
    /**
     * The one test that is not this library's opinion: the documentation
     * prints the exact string to hash for an immediate payment, and this
     * feeds in those exact fields and compares byte for byte.
     *
     * Everything else about the seal follows from this string being right.
     */
    public function test_it_reproduces_the_string_printed_in_the_documentation(): void
    {
        $expected = 'TPE=1234567*contexte_commande=CONTEXT*date=05/12/2006:11:55:23*dateech1=*dateech2=*dateech3='
            .'*dateech4=*lgue=FR*mail=internaute@sonemail.fr*montant=62.73EUR*montantech1=*montantech2=*montantech3='
            .'*montantech4=*nbrech=*options=*reference=ABERTYP00145*societe=monSite1*texte-libre=ExempleTexteLibre'
            .'*version=3.0';

        $fields = [
            'version' => '3.0',
            'TPE' => '1234567',
            'date' => '05/12/2006:11:55:23',
            'montant' => '62.73EUR',
            'reference' => 'ABERTYP00145',
            'lgue' => 'FR',
            'societe' => 'monSite1',
            'texte-libre' => 'ExempleTexteLibre',
            'mail' => 'internaute@sonemail.fr',
            'contexte_commande' => 'CONTEXT',
            'nbrech' => '',
            'dateech1' => '',
            'dateech2' => '',
            'dateech3' => '',
            'dateech4' => '',
            'montantech1' => '',
            'montantech2' => '',
            'montantech3' => '',
            'montantech4' => '',
            'options' => '',
        ];

        $this->assertSame($expected, (new AlphabeticalSeal)->canonicalString($fields));
    }

    public function test_it_reproduces_the_string_printed_for_a_split_payment(): void
    {
        $expected = 'TPE=1234567*contexte_commande=CONTEXT*date=05/12/2006:11:55:23*dateech1=05/12/2006'
            .'*dateech2=05/01/2007*dateech3=05/02/2007*dateech4=05/03/2007*lgue=FR*mail=internaute@sonemail.fr'
            .'*montant=62.73EUR*montantech1=16.23EUR*montantech2=15.5EUR*montantech3=15.5EUR*montantech4=15.5EUR'
            .'*nbrech=4*options=*reference=ABERTYP00145*societe=monSite1*texte-libre=ExempleTexteLibre*version=3.0';

        $fields = [
            'version' => '3.0',
            'TPE' => '1234567',
            'date' => '05/12/2006:11:55:23',
            'montant' => '62.73EUR',
            'reference' => 'ABERTYP00145',
            'lgue' => 'FR',
            'societe' => 'monSite1',
            'texte-libre' => 'ExempleTexteLibre',
            'mail' => 'internaute@sonemail.fr',
            'contexte_commande' => 'CONTEXT',
            'nbrech' => '4',
            'dateech1' => '05/12/2006',
            'montantech1' => '16.23EUR',
            'dateech2' => '05/01/2007',
            'montantech2' => '15.5EUR',
            'dateech3' => '05/02/2007',
            'montantech3' => '15.5EUR',
            'dateech4' => '05/03/2007',
            'montantech4' => '15.5EUR',
            'options' => '',
        ];

        $this->assertSame($expected, (new AlphabeticalSeal)->canonicalString($fields));
    }

    public function test_it_sorts_by_byte_and_not_by_php_default(): void
    {
        // ASCII order: digits, then upper case, then lower case. PHP's own
        // ksort() with SORT_REGULAR would compare "10" and "9" as numbers and
        // put them the other way round.
        $string = (new AlphabeticalSeal)->canonicalString([
            'b' => '1',
            'A' => '2',
            '9' => '3',
            '10' => '4',
            'a' => '5',
            'B' => '6',
        ]);

        $this->assertSame('10=4*9=3*A=2*B=6*a=5*b=1', $string);
    }

    public function test_the_seal_itself_is_not_part_of_what_it_seals(): void
    {
        $seal = new AlphabeticalSeal;

        $this->assertSame(
            $seal->canonicalString(['TPE' => '1234567']),
            $seal->canonicalString(['TPE' => '1234567', 'MAC' => str_repeat('a', 40)]),
        );
    }

    public function test_the_seal_is_dropped_whatever_case_it_arrives_in(): void
    {
        $seal = new AlphabeticalSeal;

        $this->assertSame(
            'TPE=1234567',
            $seal->canonicalString(['TPE' => '1234567', 'mac' => str_repeat('a', 40)]),
        );
    }

    public function test_empty_and_null_values_keep_their_place(): void
    {
        $this->assertSame(
            'a=*b=*c=something',
            (new AlphabeticalSeal)->canonicalString(['a' => '', 'b' => null, 'c' => 'something']),
        );
    }

    /**
     * The sandbox adds a field with a random name to every notification, on
     * purpose, to catch implementations that only seal the fields they know.
     */
    public function test_a_field_nobody_has_heard_of_is_part_of_the_string(): void
    {
        $string = (new AlphabeticalSeal)->canonicalString([
            'TPE' => '1234567',
            'zzUnknownField' => 'surprise',
        ]);

        $this->assertSame('TPE=1234567*zzUnknownField=surprise', $string);
    }

    public function test_it_signs_what_it_canonicalises(): void
    {
        $algorithm = new AlphabeticalSeal;
        $fields = ['TPE' => Fixtures::TERMINAL, 'montant' => '62.73EUR'];
        $key = Fixtures::credentials()->key;

        // Computed independently of the class under test.
        $expected = hash_hmac('sha1', 'TPE=1234567*montant=62.73EUR', $key->bytes());

        $this->assertSame($expected, $algorithm->seal($fields, $key)->value());
        $this->assertTrue($algorithm->matches($fields, $algorithm->seal($fields, $key), $key));
    }

    public function test_changing_one_character_of_one_field_changes_the_seal(): void
    {
        $algorithm = new AlphabeticalSeal;
        $key = Fixtures::credentials()->key;

        $honest = $algorithm->seal(['montant' => '62.73EUR'], $key);

        $this->assertFalse($algorithm->matches(['montant' => '62.74EUR'], $honest, $key));
    }

    public function test_it_is_named(): void
    {
        $this->assertSame('alphabetical', (new AlphabeticalSeal)->name());
    }
}
