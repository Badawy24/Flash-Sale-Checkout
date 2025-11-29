<?php

namespace App\Repositories;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProductRepository
{
    public function findById($id)
    {
        $cacheKey = "product_{$id}";

        return Cache::remember($cacheKey, 10, function () use ($id) {
            return Product::findOrFail($id);
        });
    }

    public function findByIdWithLock($id): Product
    {
        $startTime = microtime(true);
        $product = Product::where('id', $id)
            ->lockForUpdate()
            ->firstOrFail();

        $duration = (microtime(true) - $startTime) * 1000;

        // Log if lock acquisition took longer than expected (potential contention)
        if ($duration > 100) {
            Log::warning('Product lock acquisition took longer than expected', [
                'product_id' => $id,
                'duration_ms' => round($duration, 2),
            ]);
        }

        return $product;
    }

    public function getAvailableStock(Product $product): int
    {
        $product->refresh();
        return max(0, $product->stock - $product->reserved);
    }

    public function reserveStock(Product $product, int $quantity): void
    {
        $product->reserved += $quantity;
        $product->save();

        // Clear cache
        Cache::forget("product_{$product->id}");

        Log::debug('Stock reserved', [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'reserved' => $product->reserved,
            'available' => $product->stock - $product->reserved,
        ]);
    }

    public function releaseStock(Product $product, int $quantity): void
    {
        $product->refresh();
        $product->reserved = max(0, $product->reserved - $quantity);
        $product->save();

        // Clear cache
        Cache::forget("product_{$product->id}");

        Log::debug('Stock released', [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'reserved' => $product->reserved,
            'available' => $product->stock - $product->reserved,
        ]);
    }

    public function fulfillOrder(Product $product, int $quantity): void
    {
        // Refresh to get latest data
        $product->refresh();
        $product->stock = max(0, $product->stock - $quantity);
        $product->reserved = max(0, $product->reserved - $quantity);
        $product->save();

        // Clear cache
        Cache::forget("product_{$product->id}");

        Log::debug('Order fulfilled, stock reduced', [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'stock' => $product->stock,
            'reserved' => $product->reserved,
        ]);
    }
}
