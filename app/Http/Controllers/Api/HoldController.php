<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\HoldRequest;
use App\Repositories\HoldRepository;
use App\Repositories\ProductRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class HoldController extends Controller
{
    public function __construct(
        private ProductRepository $productRepository,
        private HoldRepository $holdRepository
    ) {}

    public function store(HoldRequest $request)
    {
        $userId = auth()->id();

        return DB::transaction(function () use ($request, $userId) {
            // Lock product using repository
            $product = $this->productRepository->findByIdWithLock($request->product_id);

            $available = $this->productRepository->getAvailableStock($product);

            if ($available < $request->quantity) {
                return response()->json(['message' => 'Not enough stock'], 422);
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

            return response()->json([
                'hold_id' => $hold->id,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ]);
        });
    }
}
