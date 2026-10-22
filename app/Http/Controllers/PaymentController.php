<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProcessPaymentRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $paymentService) {}

    /**
     * List the authenticated user's payments across all their orders, newest first.
     */
    public function index(): JsonResponse
    {
        $payments = Payment::query()
            ->whereHas('order', fn (Builder $query) => $query->where('user_id', auth('api')->id()))
            ->with('order:id,user_id')
            ->latest()
            ->paginate(15);

        return response()->json($payments);
    }

    /**
     * List payments of one of the authenticated user's orders, newest first.
     */
    public function orderPayments(int $id): JsonResponse
    {
        $order = auth('api')->user()->orders()->find($id);

        if ($order === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $payments = $order->payments()->latest()->paginate(15);

        return response()->json($payments);
    }

    /**
     * Process a payment for an order of the authenticated user.
     */
    public function store(ProcessPaymentRequest $request, int $id): JsonResponse
    {
        $order = auth('api')->user()->orders()->find($id);

        if ($order === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        try {
            $payment = $this->paymentService->process(
                $order,
                $request->validated('method'),
                $request->validated('outcome'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Payment processed.',
            'payment' => [
                'id' => $payment->id,
                'order_id' => $payment->order_id,
                'method' => $payment->method,
                'status' => $payment->status,
            ],
        ], 201);
    }
}
