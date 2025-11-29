<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CheckoutRequest;
use App\Repositories\HoldRepository;
use App\Repositories\OrderRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $startTime = microtime(true);

        try {
            return DB::transaction(function () use ($request, $userId, $startTime) {
                // Lock hold to prevent race conditions
                $hold = $this->holdRepository->findActiveByIdAndUserWithLock(
                    $request->hold_id,
                    $userId
                );

                if (!$hold) {
                    Log::warning('Checkout failed: hold not found or expired', [
                        'user_id' => $userId,
                        'hold_id' => $request->hold_id,
                    ]);

                    return response()->json([
                        'message' => 'Hold not found or expired',
                    ], 404);
                }

                // Load product relationship to calculate price
                $hold->load('product');

                // Check if hold already used (order already exists)
                $existingOrder = $this->orderRepository->findByHoldId($hold->id);
                if ($existingOrder) {
                    Log::info('Checkout attempted with already used hold', [
                        'user_id' => $userId,
                        'hold_id' => $hold->id,
                        'existing_order_id' => $existingOrder->id,
                    ]);

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

                $duration = (microtime(true) - $startTime) * 1000;

                Log::info('Order created successfully', [
                    'order_id' => $order->id,
                    'user_id' => $userId,
                    'hold_id' => $hold->id,
                    'product_id' => $hold->product_id,
                    'quantity' => $hold->quantity,
                    'price' => $order->price,
                    'duration_ms' => round($duration, 2),
                ]);

                $paymentUrl = 'https://fake-payment.com/pay/'.$order->id;

                return response()->json([
                    'order_id' => $order->id,
                    'payment_url' => $paymentUrl,
                ], 201);
            });
        } catch (\Exception $e) {
            Log::error('Checkout failed with exception', [
                'user_id' => $userId,
                'hold_id' => $request->hold_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to create order. Please try again.',
            ], 500);
        }
    }
}
