<?php

namespace App\Payments;

class PaypalGateway implements PaymentGatewayInterface
{
    public function process(string $outcome): string
    {
        return $outcome === 'success' ? 'successful' : 'failed';
    }
}
