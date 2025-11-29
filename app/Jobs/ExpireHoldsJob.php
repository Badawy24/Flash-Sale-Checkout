<?php

namespace App\Jobs;

use App\Enums\StatusEnum;
use App\Repositories\HoldRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireHoldsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        HoldRepository $holdRepository,
        OrderRepository $orderRepository,
        ProductRepository $productRepository
    ): void {
        // Find all active holds that have expired
        $expiredHolds = $holdRepository->findExpiredActiveHolds();

        if ($expiredHolds->isEmpty()) {
            return;
        }

        Log::info('Expiring holds', [
            'count' => $expiredHolds->count(),
        ]);

        $expiredCount = 0;
        $usedCount = 0;
        $skippedCount = 0;

        foreach ($expiredHolds as $hold) {
            DB::transaction(function () use ($hold, $holdRepository, $orderRepository, $productRepository, &$expiredCount, &$usedCount, &$skippedCount) {
                // Lock the hold to prevent race conditions using repository
                $lockedHold = $holdRepository->findActiveByIdWithLock($hold->id);

                if (!$lockedHold) {
                    // Hold was already processed
                    $skippedCount++;
                    return;
                }

                // Check if hold was used (order created)
                $existingOrder = $orderRepository->findByHoldId($hold->id);

                if ($existingOrder) {
                    // Hold was used, mark as used instead of expired
                    $holdRepository->markAsUsed($lockedHold);
                    $usedCount++;

                    Log::info('Hold marked as used during expiry check', [
                        'hold_id' => $lockedHold->id,
                        'order_id' => $existingOrder->id,
                    ]);
                    return;
                }

                // Mark hold as expired
                $holdRepository->markAsExpired($lockedHold);

                // Release reserved stock - lock product to prevent race conditions
                $lockedHold->load('product');
                if ($lockedHold->product) {
                    $product = $productRepository->findByIdWithLock($lockedHold->product_id);
                    $productRepository->releaseStock($product, $lockedHold->quantity);
                    $expiredCount++;

                    Log::info('Hold expired and stock released', [
                        'hold_id' => $lockedHold->id,
                        'product_id' => $product->id,
                        'quantity_released' => $lockedHold->quantity,
                        'user_id' => $lockedHold->user_id,
                    ]);
                }
            });
        }

        Log::info('Hold expiry job completed', [
            'total_found' => $expiredHolds->count(),
            'expired' => $expiredCount,
            'marked_as_used' => $usedCount,
            'skipped' => $skippedCount,
        ]);
    }
}
