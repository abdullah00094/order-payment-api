<?php

namespace Tests\Unit;

use App\Payments\CreditCardGateway;
use App\Payments\PaymentGatewayInterface;
use App\Payments\PaymentGatewayResolver;
use App\Payments\PaypalGateway;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PaymentGatewayTest extends TestCase
{
    public function test_credit_card_gateway_returns_expected_statuses(): void
    {
        $gateway = new CreditCardGateway;

        $this->assertSame('successful', $gateway->process('success'));
        $this->assertSame('failed', $gateway->process('failure'));
    }

    public function test_paypal_gateway_returns_expected_statuses(): void
    {
        $gateway = new PaypalGateway;

        $this->assertSame('successful', $gateway->process('success'));
        $this->assertSame('failed', $gateway->process('failure'));
    }

    public function test_resolver_returns_correct_strategy_for_each_method(): void
    {
        $resolver = new PaymentGatewayResolver;

        $this->assertInstanceOf(CreditCardGateway::class, $resolver->resolve('credit_card'));
        $this->assertInstanceOf(PaypalGateway::class, $resolver->resolve('paypal'));

        $this->assertInstanceOf(PaymentGatewayInterface::class, $resolver->resolve('credit_card'));
        $this->assertNotSame($resolver->resolve('credit_card'), $resolver->resolve('credit_card'), 'Resolver should return fresh gateway instances');
    }

    public function test_resolver_rejects_unsupported_payment_methods(): void
    {
        $resolver = new PaymentGatewayResolver;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported payment method [crypto].');

        $resolver->resolve('crypto');
    }
}
