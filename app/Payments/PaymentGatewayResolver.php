<?php

namespace App\Payments;

use InvalidArgumentException;

class PaymentGatewayResolver
{
    /**
     * Map of payment method identifiers to their gateway implementations,
     * read from the centralized configuration (config/payment.php).
     *
     * @return array<string, class-string<PaymentGatewayInterface>>
     */
    private function gateways(): array
    {
        /** @var array<string, class-string<PaymentGatewayInterface>> $gateways */
        $gateways = config('payment.gateways', []);

        foreach ($gateways as $gateway) {
            if (! is_subclass_of($gateway, PaymentGatewayInterface::class)) {
                throw new InvalidArgumentException(
                    "Payment gateway [{$gateway}] must implement ".PaymentGatewayInterface::class.'.'
                );
            }
        }

        return $gateways;
    }

    /**
     * Methods currently disabled through configuration.
     *
     * @return list<string>
     */
    private function disabledMethods(): array
    {
        /** @var array<string, bool> $enabled */
        $enabled = config('payment.enabled', []);

        return array_keys(array_filter($enabled, fn (mixed $isEnabled): bool => $isEnabled === false || $isEnabled === 0 || $isEnabled === '0'));
    }

    /**
     * Resolve the gateway for a payment method identifier through the
     * Laravel Service Container.
     *
     * @throws InvalidArgumentException when the method is unsupported or disabled.
     */
    public function resolve(string $method): PaymentGatewayInterface
    {
        $gateways = $this->gateways();

        $gateway = $gateways[$method] ?? null;

        if ($gateway === null) {
            throw new InvalidArgumentException("Unsupported payment method [{$method}].");
        }

        if (in_array($method, $this->disabledMethods(), true)) {
            throw new InvalidArgumentException("Payment method [{$method}] is currently disabled.");
        }

        return app($gateway);
    }

    /**
     * Payment method identifiers that are registered and currently enabled.
     *
     * @return list<string>
     */
    public function availableMethods(): array
    {
        return array_values(array_diff(array_keys($this->gateways()), $this->disabledMethods()));
    }
}
