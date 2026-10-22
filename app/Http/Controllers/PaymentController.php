<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProcessPaymentRequest;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $paymentService) {}

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
