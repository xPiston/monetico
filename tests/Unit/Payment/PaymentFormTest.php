<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use Piston\Monetico\Payment\PaymentForm;

final class PaymentFormTest extends TestCase
{
    public function test_it_keeps_the_fields_and_the_action(): void
    {
        $form = new PaymentForm(['TPE' => '1234567', 'MAC' => str_repeat('a', 40)], 'https://p.monetico-services.com/test/paiement.cgi');

        $this->assertSame(['TPE' => '1234567', 'MAC' => str_repeat('a', 40)], $form->fields());
        $this->assertSame('https://p.monetico-services.com/test/paiement.cgi', $form->action());
        $this->assertSame(str_repeat('a', 40), $form->seal());
    }

    public function test_it_renders_hidden_inputs(): void
    {
        $form = new PaymentForm(['version' => '3.0', 'TPE' => '1234567'], 'https://p.test/paiement.cgi');

        $this->assertSame(
            '<input type="hidden" name="version" value="3.0">'."\n"
            .'<input type="hidden" name="TPE" value="1234567">',
            $form->toInputs(),
        );
    }

    /**
     * The escaping happens here and only here: the seal was computed over the
     * raw value, and the apostrophe in `Rue de l'Église` has to reach the
     * browser as an apostrophe inside an attribute without ending it.
     */
    public function test_it_escapes_for_html_without_changing_what_was_sealed(): void
    {
        $value = 'Rue de l\'Église & <co> "quoted"';
        $form = new PaymentForm(['texte-libre' => $value], 'https://p.test/paiement.cgi');

        $html = $form->toInputs();

        $this->assertStringContainsString('value="Rue de l&#039;Église &amp; &lt;co&gt; &quot;quoted&quot;"', $html);
        $this->assertSame($value, $form->fields()['texte-libre']);
    }

    public function test_it_escapes_the_action_too(): void
    {
        $form = new PaymentForm([], 'https://p.test/paiement.cgi?a=1&b=2');

        $this->assertStringContainsString('action="https://p.test/paiement.cgi?a=1&amp;b=2"', $form->toHtml());
    }

    public function test_it_renders_a_whole_form(): void
    {
        $form = new PaymentForm(['TPE' => '1234567'], 'https://p.test/paiement.cgi');

        $html = $form->toHtml('Payer 62,73 €');

        $this->assertStringStartsWith('<form action="https://p.test/paiement.cgi" method="post" target="_top">', $html);
        $this->assertStringContainsString('<button type="submit">Payer 62,73 €</button>', $html);
        $this->assertStringEndsWith('</form>', $html);
    }

    public function test_the_form_attributes_can_be_replaced(): void
    {
        $form = new PaymentForm([], 'https://p.test/paiement.cgi');

        $this->assertStringContainsString(
            '<form action="https://p.test/paiement.cgi" method="post" class="checkout">',
            $form->toHtml('Pay', 'method="post" class="checkout"'),
        );
    }

    /**
     * For the iframe integration. `rawurlencode`, not `urlencode`: a space is
     * `%20` in a query string, and `+` would be read as a literal plus.
     */
    public function test_it_renders_a_url_for_the_iframe_integration(): void
    {
        $form = new PaymentForm([
            'date' => '05/12/2006:11:55:23',
            'texte-libre' => 'a b',
        ], 'https://p.test/paiement.cgi');

        $this->assertSame(
            'https://p.test/paiement.cgi?date=05%2F12%2F2006%3A11%3A55%3A23&texte-libre=a%20b',
            $form->toUrl(),
        );
    }

    public function test_a_form_without_a_seal_says_so(): void
    {
        $this->assertSame('', (new PaymentForm([], 'https://p.test/paiement.cgi'))->seal());
    }
}
