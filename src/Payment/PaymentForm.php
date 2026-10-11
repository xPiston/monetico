<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment;

/**
 * A sealed payment form: the fields, the URL they are posted to, and two ways
 * of putting them in front of a customer.
 *
 * The fields are kept raw and escaped on the way out, once, in the method that
 * knows what it is escaping for - HTML for `toHtml()`, percent-encoding for
 * `toUrl()`. Anything that escapes earlier changes the bytes the seal was
 * computed over.
 */
final class PaymentForm
{
    /** @param array<string, string> $fields */
    public function __construct(
        private readonly array $fields,
        private readonly string $action,
    ) {}

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function seal(): string
    {
        return $this->fields['MAC'] ?? '';
    }

    /**
     * The hidden inputs, HTML-escaped.
     *
     * For a template that wants to own the `<form>` element - its classes, its
     * button, its spinner - which is most of them.
     */
    public function toInputs(): string
    {
        $inputs = [];

        foreach ($this->fields as $name => $value) {
            $inputs[] = sprintf('<input type="hidden" name="%s" value="%s">', $this->escape($name), $this->escape($value));
        }

        return implode("\n", $inputs);
    }

    /** A complete form, for a redirect page or a quick test. */
    public function toHtml(string $submitLabel = 'Payer', string $attributes = 'method="post" target="_top"'): string
    {
        return sprintf(
            '<form action="%s" %s>%s<button type="submit">%s</button></form>',
            $this->escape($this->action),
            $attributes,
            "\n".$this->toInputs()."\n",
            $this->escape($submitLabel),
        );
    }

    /**
     * The same form as a URL, for the iframe integration.
     *
     * Percent-encoded per RFC 3986 - `rawurlencode`, not `urlencode`, because
     * the latter writes a space as `+`, which is correct in a form body and
     * wrong in a query string.
     */
    public function toUrl(): string
    {
        $pairs = [];

        foreach ($this->fields as $name => $value) {
            $pairs[] = rawurlencode($name).'='.rawurlencode($value);
        }

        return $this->action.'?'.implode('&', $pairs);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
