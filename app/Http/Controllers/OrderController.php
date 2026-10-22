<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->paginate(15);

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
        $order = auth('api')->user()->orders()->find($id);

        if ($order === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending orders can be updated.',
            ], 409);
        }

        $order = DB::transaction(function () use ($request, $order): Order {
            $validated = $request->validated();

            $order->fill(collect($validated)->only('customer_name', 'customer_email', 'status')->all());

            if ($request->validated('items') !== null) {
                $total = '0';

                foreach ($request->validated('items') as $item) {
                    $total = bcadd($total, bcmul((string) $item['quantity'], (string) $item['price'], 2), 2);
                }

                $order->total = $total;
                $order->save();
                $order->items()->delete();
                $order->items()->createMany(
                    collect($request->validated('items'))->map(fn (array $item): array => [
                        'product_name' => $item['product_name'],
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                    ])->all()
                );
            } else {
                $order->save();
            }

            return $order;
        });

        return response()->json([
            'message' => 'Order updated successfully.',
            'order' => $order->load('items'),
        ]);
    }

    /**
     * Create a new order with its items.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = DB::transaction(function () use ($request): Order {
            $total = '0';

            foreach ($request->validated('items') as $item) {
                $total = bcadd($total, bcmul((string) $item['quantity'], (string) $item['price'], 2), 2);
            }

            $order = Order::create([
                'user_id' => auth('api')->id(),
                'customer_name' => $request->validated('customer_name'),
                'customer_email' => $request->validated('customer_email'),
                'status' => 'pending',
                'total' => $total,
            ]);

            $order->items()->createMany(
                collect($request->validated('items'))->map(fn (array $item): array => [
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                ])->all()
            );

            return $order;
        });

        return response()->json([
            'message' => 'Order created successfully.',
            'order' => $order->load('items'),
        ], 201);
    }
}
