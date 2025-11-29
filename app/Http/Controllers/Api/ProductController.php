<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ProductRepository;

class ProductController extends Controller
{
    public function __construct(
        private ProductRepository $productRepository
    ) {}

    public function show($id)
    {
        $product = $this->productRepository->findById($id);
        $availableStock = $this->productRepository->getAvailableStock($product);

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'price' => $product->price,
            'stock' => $product->stock,
            'reserved' => $product->reserved,
            'available_stock' => $availableStock,
        ]);
    }
}
