<?php

namespace App\Payments;

class CreditCardGateway implements PaymentGatewayInterface
{
    public function process(string $outcome): string
    {
        return $outcome === 'success' ? 'successful' : 'failed';
    }
}
