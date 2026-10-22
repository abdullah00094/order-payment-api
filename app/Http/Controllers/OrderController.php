<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * List the authenticated user's orders, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', Rule::in(['pending', 'confirmed', 'cancelled'])],
        ]);

        $orders = auth('api')->user()->orders()
            ->with('items')
            ->when($request->query('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->appends($request->query());

        return response()->json($orders);
    }

    /**
     * Display one of the authenticated user's orders with its items.
     */
    public function show(int $id): JsonResponse
    {
        $order = auth('api')->user()->orders()
            ->with('items')
            ->find($id);

        if ($order === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['order' => $order]);
    }

    /**
     * Update a pending order belonging to the authenticated user.
     */
    public function update(UpdateOrderRequest $request, int $id): JsonResponse
    {
        $updated = DB::transaction(function () use ($request, $id): JsonResponse|Order {
            // Ownership-scoped lookup, row-level lock, and status check all
            // inside the same transaction as the mutations — no lock release
            // window before the writes complete.
            $order = auth('api')->user()->orders()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                return response()->json(['message' => 'Order not found.'], 404);
            }

            if ($order->status !== 'pending') {
                return response()->json([
                    'message' => 'Only pending orders can be updated.',
                ], 409);
            }

            $validated = $request->validated();

            $order->fill(collect($validated)->only('customer_name', 'customer_email', 'status')->all());

            if ($request->validated('items') !== null) {
                $order->total = $this->calculateTotal($request->validated('items'));
                $order->save();
                $order->items()->delete();
                $order->items()->createMany($this->mapItems($request->validated('items')));
            } else {
                $order->save();
            }

            return $order;
        });

        if ($updated instanceof JsonResponse) {
            return $updated;
        }

        return response()->json([
            'message' => 'Order updated successfully.',
            'order' => $updated->load('items'),
        ]);
    }

    /**
     * Create a new order with its items.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = DB::transaction(function () use ($request): Order {
            $total = $this->calculateTotal($request->validated('items'));

            $order = Order::create([
                'user_id' => auth('api')->id(),
                'customer_name' => $request->validated('customer_name'),
                'customer_email' => $request->validated('customer_email'),
                'status' => 'pending',
                'total' => $total,
            ]);

            $order->items()->createMany($this->mapItems($request->validated('items')));

            return $order;
        });

        return response()->json([
            'message' => 'Order created successfully.',
            'order' => $order->load('items'),
        ], 201);
    }

    /**
     * Delete an order of the authenticated user when it has no payments.
     */
    public function destroy(int $id): JsonResponse|Response
    {
        $deleted = DB::transaction(function () use ($id): bool {
            $order = $this->lockOrder($id);

            if ($order === null || $order->user_id !== auth('api')->id()) {
                return false;
            }

            if ($order->payments()->exists()) {
                return false;
            }

            $order->delete();

            return true;
        });

        if (! $deleted) {
            $exists = Order::query()->whereKey($id)->exists();

            if (! $exists || Order::query()->whereKey($id)->where('user_id', auth('api')->id())->doesntExist()) {
                return response()->json(['message' => 'Order not found.'], 404);
            }

            return response()->json([
                'message' => 'Orders with payments cannot be deleted.',
            ], 409);
        }

        return response()->noContent();
    }

    /**
     * Re-fetch an order with a row-level lock inside the current transaction.
     */
    private function lockOrder(int $id): ?Order
    {
        return Order::query()->whereKey($id)->lockForUpdate()->first();
    }

    /**
     * Calculate the order total from items using exact BCMath arithmetic.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function calculateTotal(array $items): string
    {
        $total = '0';

        foreach ($items as $item) {
            $total = bcadd($total, bcmul((string) $item['quantity'], (string) $item['price'], 2), 2);
        }

        return $total;
    }

    /**
     * Map validated request items to OrderItem attributes.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function mapItems(array $items): array
    {
        return collect($items)->map(fn (array $item): array => [
            'product_name' => $item['product_name'],
            'quantity' => $item['quantity'],
            'price' => $item['price'],
        ])->all();
    }
}
