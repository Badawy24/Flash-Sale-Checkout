<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PaymentWebhookRequest;
use App\Repositories\HoldRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use Illuminate\Support\Facades\DB;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private OrderRepository $orderRepository,
        private HoldRepository $holdRepository,
        private ProductRepository $productRepository
    ) {}

    public function handlePaymentWebhook(PaymentWebhookRequest $request)
    {
        return DB::transaction(function () use ($request) {

            $existingOrder = $this->orderRepository->findByPaymentWebhookReference(
                $request->idempotency_key
            );

            if ($existingOrder) {
                return response()->json([
                    'message' => 'Webhook already processed',
                    'order_status' => $existingOrder->status->value,
                ], 200);
            }

            $order = $this->orderRepository->findByIdWithLock($request->order_id);

            if (! $order) {
                return response()->json([
                    'message' => 'Order not found. Please retry.',
                ], 404);
            }

            // Check if order is already processed (status is cast to StatusEnum)
            if ($order->status !== StatusEnum::PENDING) {
                if (! $order->payment_webhook_reference) {
                    $this->orderRepository->updatePaymentWebhookReference(
                        $order,
                        $request->idempotency_key
                    );
                }

                return response()->json([
                    'message' => 'Order already processed',
                    'order_status' => $order->status->value,
                ], 200);
            }

            // Process webhook
            if ($request->status === 'paid') {
                // Update order status
                $this->orderRepository->updateStatus($order, StatusEnum::PAID);
                $this->orderRepository->updatePaymentWebhookReference(
                    $order,
                    $request->idempotency_key
                );

                // Refresh order to get latest data
                $order->refresh();

                // Fulfill order: reduce actual stock and reserved stock
                if ($order->product) {
                    // Lock product to prevent race conditions
                    $product = $this->productRepository->findByIdWithLock($order->product_id);
                    $this->productRepository->fulfillOrder($product, $order->quantity);
                }

            } else {
                // Payment failed - cancel order and release stock
                $this->orderRepository->updateStatus($order, StatusEnum::CANCELLED);
                $this->orderRepository->updatePaymentWebhookReference(
                    $order,
                    $request->idempotency_key
                );

                // Refresh order to get latest data
                $order->refresh();

                // Release reserved stock back to available
                if ($order->product) {
                    // Lock product to prevent race conditions
                    $product = $this->productRepository->findByIdWithLock($order->product_id);
                    $this->productRepository->releaseStock($product, $order->quantity);
                }

                // Mark hold as expired if still active/used
                if ($order->hold) {
                    $hold = $order->hold;
                    if (in_array($hold->status, [StatusEnum::ACTIVE, StatusEnum::USED])) {
                        $this->holdRepository->markAsExpired($hold);
                    }
                }
            }

            return response()->json([
                'message' => 'Webhook processed',
                'order_status' => $order->status->value,
            ], 200);
        });
    }
}
