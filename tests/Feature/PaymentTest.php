<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private function createConfirmedOrder(User $user): Order
    {
        return Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'confirmed',
            'total' => '45.48',
        ]);
    }

    private function pay(Order $order, array $payload, ?User $asUser = null): TestResponse
    {
        $user = $asUser ?? $order->user;

        return $this->postJson("/api/orders/{$order->id}/payments", $payload, [
            'Authorization' => 'Bearer '.auth('api')->login($user),
        ]);
    }

    public function test_successful_credit_card_payment_creates_successful_payment(): void
    {
        $user = User::factory()->create();
        $order = $this->createConfirmedOrder($user);

        $response = $this->pay($order, ['method' => 'credit_card', 'outcome' => 'success']);

        $payment = Payment::first();

        $this->assertNotNull($payment);

        $response->assertStatus(201)
            ->assertExactJson([
                'message' => 'Payment processed.',
                'payment' => [
                    'id' => $payment->id,
                    'order_id' => $order->id,
                    'method' => 'credit_card',
                    'status' => 'successful',
                ],
            ]);

        $this->assertSame('successful', $payment->status);
        $this->assertSame($order->id, $payment->order_id);
    }

    public function test_failed_paypal_payment_is_stored_as_failed_and_retry_succeeds(): void
    {
        $user = User::factory()->create();
        $order = $this->createConfirmedOrder($user);

        $failed = $this->pay($order, ['method' => 'paypal', 'outcome' => 'failure']);

        $payment = Payment::first();

        $this->assertNotNull($payment);

        $failed->assertStatus(201)
            ->assertJsonPath('payment.status', 'failed')
            ->assertJsonPath('payment.method', 'paypal');

        $this->assertSame('failed', $payment->status);
        $this->assertSame(1, Payment::count());

        $retry = $this->pay($order, ['method' => 'paypal', 'outcome' => 'success']);

        $retry->assertStatus(201)
            ->assertJsonPath('payment.status', 'successful');

        $this->assertSame(2, Payment::count());
        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_retry_after_successful_payment_returns_409_and_creates_no_duplicate(): void
    {
        $user = User::factory()->create();
        $order = $this->createConfirmedOrder($user);

        $this->pay($order, ['method' => 'credit_card', 'outcome' => 'success']);

        $retry = $this->pay($order, ['method' => 'paypal', 'outcome' => 'success']);

        $retry->assertStatus(409)
            ->assertJsonPath('message', 'This order has already been paid.');

        $this->assertSame(1, Payment::count());
        $this->assertSame('successful', Payment::sole()->status);
    }

    public function test_pending_and_cancelled_orders_reject_payment_processing(): void
    {
        $user = User::factory()->create();

        foreach (['pending', 'cancelled'] as $status) {
            $order = Order::factory()->create([
                'user_id' => $user->id,
                'status' => $status,
            ]);

            $this->pay($order, ['method' => 'credit_card', 'outcome' => 'success'])
                ->assertStatus(409)
                ->assertJsonPath('message', 'Only confirmed orders can be paid.');
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_requires_authentication(): void
    {
        $user = User::factory()->create();
        $order = $this->createConfirmedOrder($user);

        $this->postJson("/api/orders/{$order->id}/payments", [
            'method' => 'credit_card',
            'outcome' => 'success',
        ])->assertStatus(401);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_returns_404_for_missing_or_foreign_orders(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $foreignOrder = $this->createConfirmedOrder($owner);

        $this->pay($foreignOrder, ['method' => 'credit_card', 'outcome' => 'success'], $user)
            ->assertStatus(404);

        $this->pay(new Order(['id' => 999999]), ['method' => 'credit_card', 'outcome' => 'success'], $user)
            ->assertStatus(404);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_rejects_invalid_method_or_outcome_with_422(): void
    {
        $user = User::factory()->create();
        $order = $this->createConfirmedOrder($user);
        $headers = ['Authorization' => 'Bearer '.auth('api')->login($user)];

        $badMethod = $this->postJson("/api/orders/{$order->id}/payments", [
            'method' => 'crypto',
            'outcome' => 'success',
        ], $headers);
        $badMethod->assertStatus(422)->assertJsonValidationErrors(['method']);

        $badOutcome = $this->postJson("/api/orders/{$order->id}/payments", [
            'method' => 'credit_card',
            'outcome' => 'maybe',
        ], $headers);
        $badOutcome->assertStatus(422)->assertJsonValidationErrors(['outcome']);

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * Sequential duplicate-payment prevention, not true concurrency.
     *
     * Requests are issued one after another through the same locked
     * transaction path. This proves the idempotency rule (one successful
     * payment per order) but does NOT prove that truly simultaneous
     * requests are serialized — that guarantee comes from the
     * lockForUpdate() row lock inside PaymentService's transaction,
     * which this test cannot exercise without parallel execution.
     */
    public function test_sequential_repeated_processing_cannot_create_two_successful_payments(): void
    {
        $user = User::factory()->create();
        $order = $this->createConfirmedOrder($user);

        $results = [];

        collect([1, 2, 3])->each(function (int $i) use ($order, &$results): void {
            $results[] = $this->pay($order, ['method' => 'credit_card', 'outcome' => 'success'])->status();
        });

        $successful = Payment::where('status', 'successful')->count();

        $this->assertSame(1, $successful, 'Exactly one successful payment must exist');
        $this->assertSame(1, array_count_values($results)[201] ?? 0, 'Only the first request should return 201');
        $this->assertSame(2, array_count_values($results)[409] ?? 0, 'Subsequent requests must 409');
        $this->assertSame(1, Payment::count(), 'Rejected requests must not create payment rows');
    }
}
