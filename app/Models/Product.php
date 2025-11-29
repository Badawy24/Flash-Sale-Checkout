<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'price',
        'stock',
        'reserved',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock' => 'integer',
            'reserved' => 'integer',
        ];
    }

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
