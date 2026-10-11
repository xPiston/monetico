<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment\Context;

use Piston\Monetico\Field\Amount;

/**
 * One line of the basket in the order context.
 *
 * `unitPrice` is mandatory and is a *number*, not the `62.73EUR` string the
 * form fields use - the currency lives on the order, not on the line. So this
 * takes an Amount and sends its decimal value, which is the one place in this
 * library where money crosses into JSON.
 */
final class CartItem extends ContextObject
{
    protected static function label(): string
    {
        return 'contexte_commande.shoppingCart.shoppingCartItems';
    }

    protected static function allowed(): array
    {
        return ['name', 'description', 'productCode', 'imageURL', 'unitPrice', 'quantity', 'productSKU', 'productRisk'];
    }

    public static function create(string $name, Amount $unitPrice, int $quantity = 1): self
    {
        $decimals = $unitPrice->currency()->decimals();

        return self::mandatory([
            'unitPrice' => $decimals === 0
                ? $unitPrice->minor()
                : round($unitPrice->minor() / 10 ** $decimals, $decimals),
            'quantity' => $quantity,
        ])->with(['name' => $name]);
    }
}
