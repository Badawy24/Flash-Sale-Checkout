<?php

namespace App\Repositories;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

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
        return Product::where('id', $id)
            ->lockForUpdate()
            ->firstOrFail();
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
    }

    public function releaseStock(Product $product, int $quantity): void
    {
        $product->refresh();
        $product->reserved = max(0, $product->reserved - $quantity);
        $product->save();

        // Clear cache
        Cache::forget("product_{$product->id}");
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
    }
}
