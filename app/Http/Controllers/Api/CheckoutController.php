<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CheckoutRequest;
use App\Repositories\HoldRepository;
use App\Repositories\OrderRepository;
use Illuminate\Support\Facades\DB;

class CheckoutController extends Controller
{
    public function __construct(
        private HoldRepository $holdRepository,
        private OrderRepository $orderRepository
    ) {
    }

    public function checkout(CheckoutRequest $request)
    {
        $userId = auth()->id();

        return DB::transaction(function () use ($request, $userId) {
            // Lock hold to prevent race conditions
            $hold = $this->holdRepository->findActiveByIdAndUserWithLock(
                $request->hold_id,
                $userId
            );

            if (!$hold) {
                return response()->json([
                    'message' => 'Hold not found or expired',
                ], 404);
            }

            // Load product relationship to calculate price
            $hold->load('product');

            // Check if hold already used (order already exists)
            $existingOrder = $this->orderRepository->findByHoldId($hold->id);
            if ($existingOrder) {
                return response()->json([
                    'message' => 'Hold already used',
                    'order_id' => $existingOrder->id,
                ], 422);
            }

            // Create order
            $order = $this->orderRepository->create([
                'user_id' => $hold->user_id,
                'product_id' => $hold->product_id,
                'hold_id' => $hold->id,
                'quantity' => $hold->quantity,
                'price' => $hold->quantity * $hold->product->price,
                'status' => StatusEnum::PENDING->value,
            ]);

            // Mark hold as used
            $this->holdRepository->markAsUsed($hold);

            $paymentUrl = 'https://fake-payment.com/pay/'.$order->id;

            return response()->json([
                'order_id' => $order->id,
                'payment_url' => $paymentUrl,
            ]);
        });
    }
}
