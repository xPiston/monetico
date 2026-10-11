<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Piston\Monetico\Security\LegacyPositionalSeal;
use Piston\Monetico\Tests\Support\Fixtures;

final class LegacyPositionalSealTest extends TestCase
{
    /**
     * As with the current algorithm, the documentation prints the string -
     * here in the appendix that exists for shops still receiving instalments
     * created before the migration.
     */
    public function test_it_reproduces_the_string_printed_in_the_appendix(): void
    {
        $expected = '1234567*05/12/2006_a_11:55:23*62.75EUR*ABERTYP00145*LeTexteLibre*3.0*paiement*oui*1208*VI*1'
            .'*010101**FRA*010101*74E94B03C22D786E0F2C2CADBFC1C00B004B7C45*127.0.0.1*FRA*Y*Y*';

        $fields = [
            'TPE' => '1234567',
            'date' => '05/12/2006_a_11:55:23',
            'montant' => '62.75EUR',
            'reference' => 'ABERTYP00145',
            'texte-libre' => 'LeTexteLibre',
            'code-retour' => 'paiement',
            'cvx' => 'oui',
            'vld' => '1208',
            'brand' => 'VI',
            'status3ds' => '1',
            'numauto' => '010101',
            'motifrefus' => '',
            'originecb' => 'FRA',
            'bincb' => '010101',
            'hpancb' => '74E94B03C22D786E0F2C2CADBFC1C00B004B7C45',
            'ipclient' => '127.0.0.1',
            'originetr' => 'FRA',
            'veres' => 'Y',
            'pares' => 'Y',
        ];

        $this->assertSame($expected, (new LegacyPositionalSeal)->canonicalString($fields));
    }

    public function test_a_missing_field_leaves_its_separator_behind(): void
    {
        // Positional means positional: an absent field is an empty slot, not
        // one fewer slot.
        $string = (new LegacyPositionalSeal)->canonicalString(['TPE' => '1234567']);

        $this->assertSame('1234567*****3.0***************', $string);

        // Twenty slots, nineteen separators between them and one at the end.
        $this->assertSame(20, substr_count($string, '*'));
    }

    public function test_it_ends_with_a_separator(): void
    {
        $this->assertStringEndsWith('*', (new LegacyPositionalSeal)->canonicalString(['TPE' => '1234567']));
    }

    public function test_it_ignores_fields_that_are_not_in_the_sequence(): void
    {
        $sequence = new LegacyPositionalSeal;

        $this->assertSame(
            $sequence->canonicalString(['TPE' => '1234567']),
            $sequence->canonicalString(['TPE' => '1234567', 'usage' => 'credit', 'cbmasquee' => '1234']),
        );
    }

    public function test_it_signs_what_it_canonicalises(): void
    {
        $algorithm = new LegacyPositionalSeal;
        $key = Fixtures::credentials()->key;
        $fields = ['TPE' => '1234567', 'montant' => '10EUR'];

        $expected = hash_hmac('sha1', '1234567**10EUR***3.0***************', $key->bytes());

        $this->assertSame($expected, $algorithm->seal($fields, $key)->value());
    }

    public function test_it_is_named(): void
    {
        $this->assertSame('legacy-positional', (new LegacyPositionalSeal)->name());
    }
}
