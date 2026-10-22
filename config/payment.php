<?php

use App\Payments\CreditCardGateway;
use App\Payments\PaypalGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    |
    | Single source of truth for supported payment gateways. Each entry maps
    | a payment method identifier (used by clients and validation) to the
    | gateway class implementing PaymentGatewayInterface that processes it.
    |
    | Adding a gateway: create a class implementing the interface, then add
    | one line to this mapping. No other code changes are required.
    |
    | Availability: each gateway can be enabled or disabled through its
    | PAYMENT_GATEWAY_<NAME>_ENABLED environment variable. Disabled gateways
    | are rejected by validation (422) and by the resolver at runtime.
    |
    */

    'gateways' => [
        'credit_card' => CreditCardGateway::class,
        'paypal' => PaypalGateway::class,
    ],

    'enabled' => [
        'credit_card' => env('PAYMENT_GATEWAY_CREDIT_CARD_ENABLED', true),
        'paypal' => env('PAYMENT_GATEWAY_PAYPAL_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Simulated Payment Outcomes
    |--------------------------------------------------------------------------
    |
    | Outcome identifiers accepted by the simulated gateways. The "outcome"
    | field exists only to control simulation behavior; it does not represent
    | a real gateway parameter.
    |
    */

    'outcomes' => [
        'success' => 'successful',
        'failure' => 'failed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Order Totals
    |--------------------------------------------------------------------------
    |
    | Maximum storable order total, matching the orders.total column
    | decimal(12,2). Aggregate totals are validated against this limit
    | before any persistence happens.
    |
    */

    'max_total' => '9999999999.99',

];
