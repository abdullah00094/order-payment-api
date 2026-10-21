<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Ahmed Ali',
            'customer_email' => 'ahmed@example.com',
            'items' => [
                ['product_name' => 'Widget', 'quantity' => 2, 'price' => '19.99'],
                ['product_name' => 'Gadget', 'quantity' => 1, 'price' => '5.50'],
            ],
        ], $overrides);
    }

    private function authToken(User $user): string
    {
        return auth('api')->login($user);
    }

    public function test_authenticated_request_creates_order_with_items_and_server_total(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/orders', $this->validPayload(), [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('order.user_id', $user->id)
            ->assertJsonPath('order.status', 'pending')
            ->assertJsonPath('order.total', '45.48')
            ->assertJsonCount(2, 'order.items');

        $order = Order::first();

        $this->assertNotNull($order);
        $this->assertSame($user->id, $order->user_id);
        $this->assertSame('pending', $order->status);
        $this->assertSame('45.48', $order->total);
        $this->assertSame(2, $order->items()->count());

        $firstItem = $order->items()->first();
        $this->assertSame(2, $firstItem->quantity);
        $this->assertSame('19.99', $firstItem->price);
    }

    public function test_invalid_request_data_returns_422(): void
    {
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$this->authToken($user)];

        $emptyItems = $this->postJson('/api/orders', $this->validPayload(['items' => []]), $headers);
        $emptyItems->assertStatus(422)->assertJsonValidationErrors(['items']);

        $badQuantity = $this->postJson('/api/orders', $this->validPayload([
            'items' => [['product_name' => 'Widget', 'quantity' => 0, 'price' => '10.00']],
        ]), $headers);
        $badQuantity->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);

        $badPrice = $this->postJson('/api/orders', $this->validPayload([
            'items' => [['product_name' => 'Widget', 'quantity' => 1, 'price' => '10.999']],
        ]), $headers);
        $badPrice->assertStatus(422)->assertJsonValidationErrors(['items.0.price']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_unauthenticated_request_returns_401_and_creates_no_order(): void
    {
        $this->postJson('/api/orders', $this->validPayload())
            ->assertStatus(401);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_client_supplied_total_and_user_id_cannot_override_server_values(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $payload = $this->validPayload([
            'user_id' => $otherUser->id,
            'total' => '0.01',
            'status' => 'paid',
        ]);

        $response = $this->postJson('/api/orders', $payload, [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ]);

        $response->assertStatus(201);

        $order = Order::first();

        $this->assertSame($user->id, $order->user_id);
        $this->assertNotSame($otherUser->id, $order->user_id);
        $this->assertSame('45.48', $order->total);
        $this->assertSame('pending', $order->status);
    }
}
