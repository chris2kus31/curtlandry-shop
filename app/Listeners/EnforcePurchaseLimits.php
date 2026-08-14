<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Webkul\Checkout\Facades\Cart;

/**
 * Enforces the per-customer purchase limit attribute
 * (`purchase_limit_per_customer`, created by catalog:setup-custom-attributes).
 *
 * Runs after every cart add/update — covers the Blade storefront, the
 * headless GraphQL API, and anything else that goes through the Cart facade.
 *
 * Rules:
 *  - limit blank/0  → unlimited (default for every product).
 *  - logged-in customer → cart qty + qty from their past (non-canceled)
 *    orders must not exceed the limit.
 *  - guest → only the cart qty is capped (no history to check; final
 *    enforcement for guests-turned-customers happens naturally on their
 *    next limited purchase).
 *
 * On violation the cart is corrected (qty clamped to what's still allowed,
 * or the item removed) and an exception with a shopper-friendly message is
 * thrown — both the shop controllers and the GraphQL mutations catch it and
 * surface the message.
 */
class EnforcePurchaseLimits
{
    /** Prevents recursion when we correct the cart from inside a cart event. */
    protected static bool $enforcing = false;

    public function handle(mixed $payload = null): void
    {
        if (self::$enforcing) {
            return;
        }

        $cart = Cart::getCart();

        if (! $cart) {
            return;
        }

        foreach ($cart->items as $item) {
            $product = $item->product;

            if (! $product) {
                continue;
            }

            $limit = (int) ($product->purchase_limit_per_customer ?? 0);

            if ($limit <= 0) {
                continue;
            }

            $alreadyPurchased = 0;

            if ($cart->customer_id) {
                $alreadyPurchased = (int) DB::table('order_items')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.customer_id', $cart->customer_id)
                    ->whereNotIn('orders.status', ['canceled'])
                    ->where('order_items.product_id', $product->id)
                    ->sum('order_items.qty_ordered');
            }

            $allowed = max(0, $limit - $alreadyPurchased);

            if ($item->quantity <= $allowed) {
                continue;
            }

            self::$enforcing = true;

            try {
                if ($allowed > 0) {
                    Cart::updateItems(['qty' => [$item->id => $allowed]]);
                } else {
                    Cart::removeItem($item->id);
                    Cart::collectTotals();
                }
            } finally {
                self::$enforcing = false;
            }

            $message = $alreadyPurchased > 0
                ? "\"{$product->name}\" is limited to {$limit} per customer and you have already purchased {$alreadyPurchased}."
                : "\"{$product->name}\" is limited to {$limit} per customer.";

            throw new \Exception($allowed > 0
                ? $message." Your cart quantity was adjusted to {$allowed}."
                : $message.' It was removed from your cart.');
        }
    }
}
