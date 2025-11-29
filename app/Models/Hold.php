<?php

namespace App\Models;

use App\Enums\StatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Hold extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'quantity',
        'expired_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'expired_at' => 'datetime',
            'quantity' => 'integer',
            'status' => StatusEnum::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }
}
