<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hold;
use App\Models\Product;
use App\Http\Requests\Api\HoldRequest;
use App\Enums\StatusEnum;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class HoldController extends Controller
{
    public function store(HoldRequest $request)
    {
        $userId = auth()->id();

        return DB::transaction(function () use ($request, $userId) {

            // Lock product
            $product = Product::where('id', $request->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            $available = $product->stock - $product->reserved;

            if ($available < $request->quantity) {
                return response()->json(['message' => 'Not enough stock'], 422);
            }

            // Reserve stock
            $product->reserved += $request->quantity;
            $product->save();

            // Create hold
            $expiresAt = Carbon::now()->addMinutes(2);

            $hold = Hold::create([
                'user_id'    => $userId,
                'product_id' => $product->id,
                'quantity'   => $request->quantity,
                'expired_at' => $expiresAt,
                'status'     => StatusEnum::ACTIVE->value,
            ]);

            return response()->json([
                'hold_id' => $hold->id,
                'expires_at' => $expiresAt,
            ]);
        });
    }
}
