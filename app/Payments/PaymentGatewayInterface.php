<?php

namespace App\Payments;

interface PaymentGatewayInterface
{
    /**
     * Process a simulated payment and return the resulting status.
     *
     * @param  string  $outcome  The simulated outcome: "success" or "failure".
     * @return string The payment status: "successful" or "failed".
     */
    public function process(string $outcome): string;
}
