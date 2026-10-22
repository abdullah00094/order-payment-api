<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\PaymentGatewayResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentService
{
    public function __construct(private PaymentGatewayResolver $resolver) {}

    /**
     * Process a payment for a confirmed order.
     *
     * @throws RuntimeException when a business rule rejects the payment.
     */
    public function process(Order $order, string $method, string $outcome): Payment
    {
        return DB::transaction(function () use ($order, $method, $outcome): Payment {
            $order = $order->newQuery()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($order->status !== 'confirmed') {
                throw new RuntimeException('Only confirmed orders can be paid.');
            }

            $hasSuccessfulPayment = $order->payments()
                ->where('status', 'successful')
                ->lockForUpdate()
                ->exists();

            if ($hasSuccessfulPayment) {
                throw new RuntimeException('This order has already been paid.');
            }

            $status = $this->resolver->resolve($method)->process($outcome);

            return $order->payments()->create([
                'method' => $method,
                'status' => $status,
            ]);
        });
    }
}
