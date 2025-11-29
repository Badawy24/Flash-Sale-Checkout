<?php

namespace App\Repositories;

use App\Enums\StatusEnum;
use App\Models\Order;

class OrderRepository
{
    public function create(array $data): Order
    {
        return Order::create($data);
    }

    public function findById($orderId): ?Order
    {
        return Order::find($orderId);
    }

    public function findByIdWithLock($orderId): ?Order
    {
        return Order::with(['product', 'hold'])
            ->where('id', $orderId)
            ->lockForUpdate()
            ->first();
    }

    public function findByHoldId($holdId): ?Order
    {
        return Order::where('hold_id', $holdId)->first();
    }

    public function findByPaymentWebhookReference($idempotencyKey): ?Order
    {
        return Order::where('payment_webhook_reference', $idempotencyKey)->first();
    }

    public function updateStatus(Order $order, StatusEnum $status): void
    {
        $order->status = $status;
        $order->save();
    }

    public function updatePaymentWebhookReference(Order $order, string $idempotencyKey): void
    {
        $order->payment_webhook_reference = $idempotencyKey;
        $order->save();
    }
}
