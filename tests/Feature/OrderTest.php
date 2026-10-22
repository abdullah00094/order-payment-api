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

    public function test_listing_returns_paginated_orders_of_authenticated_user_with_status_filter(): void
    {
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$this->authToken($user)];
        $otherUser = User::factory()->create();

        $statuses = ['pending', 'pending', 'confirmed', 'cancelled'];

        foreach ($statuses as $index => $status) {
            Order::factory()->create([
                'user_id' => $user->id,
                'status' => $status,
                'created_at' => now()->subMinutes(count($statuses) - $index),
            ]);
        }

        Order::factory()->create(['user_id' => $otherUser->id, 'status' => 'pending']);

        $all = $this->getJson('/api/orders', $headers);

        $all->assertStatus(200)
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('per_page', 15)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('total', 4)
            ->assertJsonStructure(['data', 'current_page', 'per_page', 'total', 'last_page', 'links'])
            ->assertJsonMissing(['user_id' => $otherUser->id]);

        $data = collect($all->json('data'));
        $this->assertSame(['cancelled', 'confirmed', 'pending', 'pending'], $data->pluck('status')->all(), 'Orders must be newest first');
        $this->assertArrayHasKey('items', $data->first(), 'Items must be eager loaded');

        $filtered = $this->getJson('/api/orders?status=pending', $headers);

        $filtered->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_listing_rejects_invalid_status(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/orders?status=shipped', [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_single_order_is_retrieved_with_items(): void
    {
        $user = User::factory()->create();

        $created = $this->postJson('/api/orders', $this->validPayload(), [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ])->json('order');

        $show = $this->getJson("/api/orders/{$created['id']}", [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ]);

        $show->assertStatus(200)
            ->assertJsonPath('order.id', $created['id'])
            ->assertJsonPath('order.total', '45.48')
            ->assertJsonCount(2, 'order.items');
    }

    public function test_user_cannot_view_another_users_order(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();

        $created = $this->postJson('/api/orders', $this->validPayload(), [
            'Authorization' => 'Bearer '.$this->authToken($owner),
        ])->json('order');

        $this->getJson("/api/orders/{$created['id']}", [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ])->assertStatus(404);

        $this->getJson('/api/orders/999999', [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ])->assertStatus(404);
    }

    public function test_order_read_endpoints_require_authentication(): void
    {
        $this->getJson('/api/orders')->assertStatus(401);
        $this->getJson('/api/orders/1')->assertStatus(401);
    }

    private function createOrder(User $user, string $status = 'pending', array $overrides = []): Order
    {
        $order = Order::factory()->create(array_merge([
            'user_id' => $user->id,
            'status' => $status,
            'total' => '45.48',
        ], $overrides));

        $order->items()->createMany([
            ['product_name' => 'Widget', 'quantity' => 2, 'price' => '19.99'],
            ['product_name' => 'Gadget', 'quantity' => 1, 'price' => '5.50'],
        ]);

        return $order->refresh();
    }

    public function test_pending_order_customer_details_update_preserves_items_and_total(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user);

        $response = $this->patchJson("/api/orders/{$order->id}", [
            'customer_name' => 'Sara Hassan',
            'customer_email' => 'sara@example.com',
        ], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('order.customer_name', 'Sara Hassan')
            ->assertJsonPath('order.customer_email', 'sara@example.com')
            ->assertJsonPath('order.total', '45.48')
            ->assertJsonCount(2, 'order.items');

        $order->refresh();

        $this->assertSame('45.48', $order->total);
        $this->assertSame('pending', $order->status);
        $this->assertSame(2, $order->items()->count());
    }

    public function test_replacing_items_removes_old_items_and_recalculates_total(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user);

        $response = $this->patchJson("/api/orders/{$order->id}", [
            'items' => [
                ['product_name' => 'Keyboard', 'quantity' => 3, 'price' => '10.00'],
                ['product_name' => 'Mouse', 'quantity' => 1, 'price' => '15.25'],
                ['product_name' => 'Cable', 'quantity' => 2, 'price' => '0.99'],
            ],
        ], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('order.total', '47.23')
            ->assertJsonCount(3, 'order.items');

        $order->refresh();

        $this->assertSame('47.23', $order->total);
        $this->assertSame(3, $order->items()->count());
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id, 'product_name' => 'Widget']);
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id, 'product_name' => 'Gadget']);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_name' => 'Keyboard', 'price' => '10.00']);
    }

    public function test_non_pending_orders_reject_updates_with_409(): void
    {
        $user = User::factory()->create();

        foreach (['confirmed', 'cancelled'] as $status) {
            $order = $this->createOrder($user, $status);

            $this->patchJson("/api/orders/{$order->id}", [
                'customer_name' => 'Blocked Update',
            ], [
                'Authorization' => 'Bearer '.$this->authToken($user),
            ])->assertStatus(409)
                ->assertJsonPath('message', 'Only pending orders can be updated.');

            $order->refresh();

            $this->assertNotSame('Blocked Update', $order->customer_name);
            $this->assertSame($status, $order->status);
        }
    }

    public function test_update_returns_404_for_missing_or_foreign_orders(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();

        $foreignOrder = $this->createOrder($owner);

        $this->patchJson('/api/orders/999999', [
            'customer_name' => 'No Such Order',
        ], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ])->assertStatus(404);

        $this->patchJson("/api/orders/{$foreignOrder->id}", [
            'customer_name' => 'Hijacked',
        ], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ])->assertStatus(404);

        $foreignOrder->refresh();

        $this->assertNotSame('Hijacked', $foreignOrder->customer_name);
    }

    public function test_update_rejects_invalid_data_with_422(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user);
        $headers = ['Authorization' => 'Bearer '.$this->authToken($user)];

        $emptyItems = $this->patchJson("/api/orders/{$order->id}", ['items' => []], $headers);
        $emptyItems->assertStatus(422)->assertJsonValidationErrors(['items']);

        $badQuantity = $this->patchJson("/api/orders/{$order->id}", [
            'items' => [['product_name' => 'X', 'quantity' => 0, 'price' => '1.00']],
        ], $headers);
        $badQuantity->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);

        $badPrice = $this->patchJson("/api/orders/{$order->id}", [
            'items' => [['product_name' => 'X', 'quantity' => 1, 'price' => '1.999']],
        ], $headers);
        $badPrice->assertStatus(422)->assertJsonValidationErrors(['items.0.price']);

        $order->refresh();

        $this->assertSame(2, $order->items()->count());
    }

    public function test_update_requires_authentication(): void
    {
        $this->patchJson('/api/orders/1', ['customer_name' => 'X'])
            ->assertStatus(401);
    }

    public function test_update_cannot_modify_protected_fields(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $order = $this->createOrder($user);

        $response = $this->patchJson("/api/orders/{$order->id}", [
            'user_id' => $otherUser->id,
            'status' => 'confirmed',
            'total' => '0.01',
            'order_id' => 999,
            'items' => [
                ['product_name' => 'Solo Item', 'quantity' => 1, 'price' => '7.77'],
            ],
        ], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ]);

        $response->assertStatus(200);

        $order->refresh();

        $this->assertSame($user->id, $order->user_id);
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('7.77', $order->total);
        $this->assertSame($order->id, $order->items()->first()->order_id);
    }

    public function test_pending_order_can_transition_to_confirmed_or_cancelled(): void
    {
        $user = User::factory()->create();

        $pending = $this->createOrder($user);

        $confirmed = $this->patchJson("/api/orders/{$pending->id}", [
            'status' => 'confirmed',
        ], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ]);

        $confirmed->assertStatus(200)
            ->assertJsonPath('order.status', 'confirmed');

        $this->assertSame('confirmed', $pending->refresh()->status);

        $cancelled = $this->createOrder($user);

        $cancelledUpdate = $this->patchJson("/api/orders/{$cancelled->id}", [
            'status' => 'cancelled',
        ], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ]);

        $cancelledUpdate->assertStatus(200)
            ->assertJsonPath('order.status', 'cancelled');

        $this->assertSame('cancelled', $cancelled->refresh()->status);
    }

    public function test_status_transition_rejects_invalid_status_with_422(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user);

        $this->patchJson("/api/orders/{$order->id}", [
            'status' => 'shipped',
        ], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $order->refresh();

        $this->assertSame('pending', $order->status);
    }

    public function test_confirmed_and_cancelled_orders_cannot_be_modified_afterwards(): void
    {
        $user = User::factory()->create();

        $confirmed = $this->createOrder($user);
        $confirmed->update(['status' => 'confirmed']);

        $this->patchJson("/api/orders/{$confirmed->id}", [
            'status' => 'pending',
        ], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ])->assertStatus(409);

        $this->patchJson("/api/orders/{$confirmed->id}", [
            'customer_name' => 'Should Not Apply',
        ], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ])->assertStatus(409);

        $cancelled = $this->createOrder($user);
        $cancelled->update(['status' => 'cancelled']);

        $this->patchJson("/api/orders/{$cancelled->id}", [
            'items' => [['product_name' => 'X', 'quantity' => 1, 'price' => '1.00']],
        ], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ])->assertStatus(409);

        $confirmed->refresh();
        $cancelled->refresh();

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertNotSame('Should Not Apply', $confirmed->customer_name);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(2, $cancelled->items()->count());
    }

    public function test_status_transition_combines_with_customer_and_items_updates(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user);

        $response = $this->patchJson("/api/orders/{$order->id}", [
            'status' => 'confirmed',
            'customer_email' => 'updated@example.com',
            'items' => [
                ['product_name' => 'New Item', 'quantity' => 2, 'price' => '12.50'],
            ],
        ], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('order.status', 'confirmed')
            ->assertJsonPath('order.customer_email', 'updated@example.com')
            ->assertJsonPath('order.total', '25.00')
            ->assertJsonCount(1, 'order.items');
    }

    private function createOrderWithItems(User $user, string $status = 'pending'): Order
    {
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => $status]);
        $order->items()->create(['product_name' => 'Widget', 'quantity' => 1, 'price' => '10.00']);

        return $order->refresh();
    }

    public function test_deleting_paymentless_order_removes_order_and_items(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrderWithItems($user);
        $itemId = $order->items()->first()->id;

        $this->deleteJson("/api/orders/{$order->id}", [], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ])->assertStatus(204)
            ->assertNoContent();

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('order_items', ['id' => $itemId]);
    }

    public function test_orders_with_any_payment_status_cannot_be_deleted(): void
    {
        $user = User::factory()->create();

        foreach (['pending', 'successful', 'failed'] as $status) {
            $order = $this->createOrderWithItems($user);

            $order->payments()->create(['method' => 'credit_card', 'status' => $status]);

            $this->deleteJson("/api/orders/{$order->id}", [], [
                'Authorization' => 'Bearer '.$this->authToken($user),
            ])->assertStatus(409)
                ->assertJsonPath('message', 'Orders with payments cannot be deleted.');

            $this->assertDatabaseHas('orders', ['id' => $order->id]);
            $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => $status]);
        }
    }

    public function test_delete_returns_404_for_missing_or_foreign_orders(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $foreignOrder = $this->createOrderWithItems($owner);

        $this->deleteJson('/api/orders/999999', [], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ])->assertStatus(404);

        $this->deleteJson("/api/orders/{$foreignOrder->id}", [], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ])->assertStatus(404);

        $this->assertDatabaseHas('orders', ['id' => $foreignOrder->id]);
    }

    public function test_delete_requires_authentication(): void
    {
        $this->deleteJson('/api/orders/1')->assertStatus(401);
    }

    public function test_deletion_is_independent_of_order_status_when_paymentless(): void
    {
        $user = User::factory()->create();

        foreach (['pending', 'confirmed', 'cancelled'] as $status) {
            $order = $this->createOrderWithItems($user, $status);

            $this->deleteJson("/api/orders/{$order->id}", [], [
                'Authorization' => 'Bearer '.$this->authToken($user),
            ])->assertStatus(204);
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_routes_return_404_for_malformed_non_numeric_ids(): void
    {
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$this->authToken($user)];

        $this->getJson('/api/orders/abc', $headers)->assertStatus(404);
        $this->patchJson('/api/orders/abc', ['customer_name' => 'X'], $headers)->assertStatus(404);
        $this->deleteJson('/api/orders/abc', [], $headers)->assertStatus(404);
        $this->postJson('/api/orders/abc/payments', [
            'method' => 'credit_card',
            'outcome' => 'success',
        ], $headers)->assertStatus(404);
        $this->getJson('/api/orders/abc/payments', $headers)->assertStatus(404);
    }

    public function test_numeric_overflow_inputs_return_422_without_database_errors(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user);
        $headers = ['Authorization' => 'Bearer '.$this->authToken($user)];

        $oversizedPrice = $this->postJson('/api/orders', $this->validPayload([
            'items' => [['product_name' => 'X', 'quantity' => 1, 'price' => '99999999999.99']],
        ]), $headers);
        $oversizedPrice->assertStatus(422)->assertJsonValidationErrors(['items.0.price']);

        $oversizedQuantity = $this->patchJson("/api/orders/{$order->id}", [
            'items' => [['product_name' => 'X', 'quantity' => 4294967296, 'price' => '1.00']],
        ], $headers);
        $oversizedQuantity->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);

        $this->assertDatabaseCount('orders', 1);
        $order->refresh();
        $this->assertSame('45.48', $order->total, 'Rejected input must not modify the order');
    }

    public function test_order_update_still_enforces_status_restrictions(): void
    {
        $user = User::factory()->create();
        $confirmed = $this->createOrderWithItems($user, 'confirmed');

        $this->patchJson("/api/orders/{$confirmed->id}", [
            'customer_name' => 'Should Not Apply',
        ], [
            'Authorization' => 'Bearer '.$this->authToken($user),
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Only pending orders can be updated.');

        $confirmed->refresh();

        $this->assertNotSame('Should Not Apply', $confirmed->customer_name);
        $this->assertSame('confirmed', $confirmed->status);
    }

    public function test_aggregate_total_overflow_returns_422_on_create_and_update(): void
    {
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$this->authToken($user)];

        // Individually valid items whose aggregate exceeds decimal(12,2).
        $items = [
            ['product_name' => 'Gold Bar', 'quantity' => 3, 'price' => '4000000000.00'],
            ['product_name' => 'Gold Bar', 'quantity' => 3, 'price' => '4000000000.00'],
        ];

        $create = $this->postJson('/api/orders', $this->validPayload(['items' => $items]), $headers);

        $create->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        $this->assertSame('9999999999.99', config('payment.max_total'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);

        // Update with item replacement: rejected, existing order untouched.
        $order = $this->createOrder($user);
        $itemId = $order->items()->first()->id;

        $update = $this->patchJson("/api/orders/{$order->id}", ['items' => $items], $headers);

        $update->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        $order->refresh();

        $this->assertSame('45.48', $order->total, 'Rejected update must not change the total');
        $this->assertSame(2, $order->items()->count(), 'Rejected update must not touch existing items');
        $this->assertDatabaseHas('order_items', ['id' => $itemId, 'product_name' => 'Widget']);

        // Boundary: aggregate exactly at the limit remains valid.
        $boundary = $this->postJson('/api/orders', $this->validPayload([
            'items' => [['product_name' => 'Max Order', 'quantity' => 1, 'price' => '9999999999.99']],
        ]), $headers);

        $boundary->assertStatus(201)
            ->assertJsonPath('order.total', '9999999999.99');
    }

    public function test_malformed_numeric_input_returns_422_without_server_error(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user);
        $headers = ['Authorization' => 'Bearer '.$this->authToken($user)];

        $badQuantityCreate = $this->postJson('/api/orders', $this->validPayload([
            'items' => [['product_name' => 'X', 'quantity' => 'abc', 'price' => '2.00']],
        ]), $headers);
        $badQuantityCreate->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);

        $badPriceCreate = $this->postJson('/api/orders', $this->validPayload([
            'items' => [['product_name' => 'X', 'quantity' => 1, 'price' => '1.5.5']],
        ]), $headers);
        $badPriceCreate->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.price']);

        $badQuantityUpdate = $this->patchJson("/api/orders/{$order->id}", [
            'items' => [['product_name' => 'X', 'quantity' => '2x', 'price' => '2.00']],
        ], $headers);
        $badQuantityUpdate->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);

        $order->refresh();

        $this->assertSame('45.48', $order->total);
        $this->assertSame(2, $order->items()->count());
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_filtered_order_pagination_preserves_status_in_links(): void
    {
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$this->authToken($user)];

        for ($i = 1; $i <= 18; $i++) {
            Order::factory()->create([
                'user_id' => $user->id,
                'status' => 'pending',
                'created_at' => now()->subMinutes(20 - $i),
            ]);
        }

        $response = $this->getJson('/api/orders?status=pending', $headers);

        $response->assertStatus(200)
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('total', 18);

        $nextPageUrl = $response->json('next_page_url');

        $this->assertNotNull($nextPageUrl);
        $this->assertStringContainsString('status=pending', $nextPageUrl, 'Pagination links must preserve the status filter');

        $page2 = $this->getJson($nextPageUrl, $headers);

        $page2->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('current_page', 2);
    }
}
