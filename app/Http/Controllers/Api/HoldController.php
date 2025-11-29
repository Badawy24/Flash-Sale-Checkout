<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\HoldRequest;
use App\Repositories\HoldRepository;
use App\Repositories\ProductRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HoldController extends Controller
{
    public function __construct(
        private ProductRepository $productRepository,
        private HoldRepository $holdRepository
    ) {}

    public function store(HoldRequest $request)
    {
        $userId = auth()->id();
        $startTime = microtime(true);

        try {
            return DB::transaction(function () use ($request, $userId, $startTime) {
                // Lock product using repository
                $product = $this->productRepository->findByIdWithLock($request->product_id);

                $available = $this->productRepository->getAvailableStock($product);

                if ($available < $request->quantity) {
                    Log::info('Hold creation failed: insufficient stock', [
                        'user_id' => $userId,
                        'product_id' => $request->product_id,
                        'requested_quantity' => $request->quantity,
                        'available_stock' => $available,
                    ]);

                    return response()->json([
                        'message' => 'Not enough stock',
                        'available_stock' => $available,
                    ], 422);
                }

                // Reserve stock
                $this->productRepository->reserveStock($product, $request->quantity);

                // Create hold
                $expiresAt = Carbon::now()->addMinutes(2);

                $hold = $this->holdRepository->create([
                    'user_id' => $userId,
                    'product_id' => $product->id,
                    'quantity' => $request->quantity,
                    'expired_at' => $expiresAt,
                    'status' => StatusEnum::ACTIVE->value,
                ]);

                $duration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

                Log::info('Hold created successfully', [
                    'hold_id' => $hold->id,
                    'user_id' => $userId,
                    'product_id' => $product->id,
                    'quantity' => $request->quantity,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'duration_ms' => round($duration, 2),
                ]);

                return response()->json([
                    'hold_id' => $hold->id,
                    'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                ], 201);
            });
        } catch (\Exception $e) {
            Log::error('Hold creation failed with exception', [
                'user_id' => $userId,
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to create hold. Please try again.',
            ], 500);
        }
    }
}
