<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
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
