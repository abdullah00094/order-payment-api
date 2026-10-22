<?php

namespace App\Payments;

use InvalidArgumentException;

class PaymentGatewayResolver
{
    /**
     * Map of payment method identifiers to their gateway implementations.
     *
     * @var array<string, class-string<PaymentGatewayInterface>>
     */
    private array $gateways = [
        'credit_card' => CreditCardGateway::class,
        'paypal' => PaypalGateway::class,
    ];

    /**
     * Resolve the gateway for a payment method identifier.
     */
    public function resolve(string $method): PaymentGatewayInterface
    {
        $gateway = $this->gateways[$method] ?? null;

        if ($gateway === null) {
            throw new InvalidArgumentException("Unsupported payment method [{$method}].");
        }

        return new $gateway;
    }
}
