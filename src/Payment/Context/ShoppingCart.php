<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment\Context;

/**
 * The `shoppingCart` object: what is being bought, plus the gift-card and
 * pre-order attributes the issuer scores against.
 */
final class ShoppingCart extends ContextObject
{
    protected static function label(): string
    {
        return 'contexte_commande.shoppingCart';
    }

    protected static function allowed(): array
    {
        return [
            'giftCardAmount', 'giftCardCount', 'giftCardCurrency',
            'preOrderDate', 'preorderIndicator', 'reorderIndicator', 'shoppingCartItems',
        ];
    }

    public static function of(CartItem ...$items): self
    {
        return (new self)->with([
            'shoppingCartItems' => array_map(static fn (CartItem $item): array => $item->toArray(), $items),
        ]);
    }
}
