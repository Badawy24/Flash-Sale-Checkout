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

        foreach ($expiredHolds as $hold) {
            DB::transaction(function () use ($hold, $holdRepository, $orderRepository, $productRepository) {
                // Lock the hold to prevent race conditions using repository
                $lockedHold = $holdRepository->findActiveByIdWithLock($hold->id);

                if (!$lockedHold) {
                    // Hold was already processed
                    return;
                }

                // Check if hold was used (order created)
                $existingOrder = $orderRepository->findByHoldId($hold->id);

                if ($existingOrder) {
                    // Hold was used, mark as used instead of expired
                    $holdRepository->markAsUsed($lockedHold);
                    return;
                }

                // Mark hold as expired
                $holdRepository->markAsExpired($lockedHold);

                // Release reserved stock - lock product to prevent race conditions
                $lockedHold->load('product');
                if ($lockedHold->product) {
                    $product = $productRepository->findByIdWithLock($lockedHold->product_id);
                    $productRepository->releaseStock($product, $lockedHold->quantity);

                    Log::info('Hold expired and stock released', [
                        'hold_id' => $lockedHold->id,
                        'product_id' => $product->id,
                        'quantity_released' => $lockedHold->quantity,
                    ]);
                }
            });
        }
    }
}
