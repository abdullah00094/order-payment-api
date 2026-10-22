<?php

namespace App\Http\Requests\Concerns;

use Closure;

/**
 * Shared numeric validation bounds matching the actual database columns:
 * quantity → unsigned integer, price/total → decimal(12,2).
 */
trait ValidatesOrderItems
{
    /**
     * Rules for the "items" array itself. Callers must merge these with
     * their own "items" rules (required/sometimes, array, min:1).
     *
     * @return array<int, mixed>
     */
    protected function itemsArrayRules(): array
    {
        return [$this->validateAggregateTotal(...)];
    }

    /**
     * Rules for each item inside "items.*".
     *
     * @return array<string, array<int, mixed>>
     */
    protected function orderItemRules(): array
    {
        return [
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'items.*.price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function orderItemMessages(): array
    {
        return [
            'items.*.price.regex' => 'The :attribute must have at most 2 decimal places.',
            'items.*.price.max' => 'The :attribute may not be greater than 9999999999.99.',
        ];
    }

    /**
     * Reject the request when the aggregate order total exceeds the
     * maximum storable value of the orders.total decimal(12,2) column.
     *
     * @param  array<int, array<string, mixed>>|mixed  $value
     */
    protected function validateAggregateTotal(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            return;
        }

        $maxTotal = (string) config('payment.max_total', '9999999999.99');
        $total = '0';

        foreach ($value as $item) {
            if (! is_array($item) || ! isset($item['quantity'], $item['price'])) {
                return;
            }

            $total = bcadd($total, bcmul((string) $item['quantity'], (string) $item['price'], 2), 2);
        }

        if (bccomp($total, $maxTotal, 2) === 1) {
            $fail("The order total {$total} exceeds the maximum allowed total of {$maxTotal}.")->translate();
        }
    }
}
