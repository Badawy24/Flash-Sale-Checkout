<?php

namespace App\Repositories;

use App\Enums\StatusEnum;
use App\Models\Hold;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class HoldRepository
{
    public function create(array $data): Hold
    {
        return Hold::create($data);
    }

    public function findActiveByIdAndUser($holdId, $userId): ?Hold
    {
        return Hold::where('id', $holdId)
            ->where('user_id', $userId)
            ->where('status', StatusEnum::ACTIVE->value)
            ->where('expired_at', '>', now())
            ->first();
    }

    public function findActiveByIdAndUserWithLock($holdId, $userId): ?Hold
    {
        return Hold::where('id', $holdId)
            ->where('user_id', $userId)
            ->where('status', StatusEnum::ACTIVE->value)
            ->where('expired_at', '>', now())
            ->lockForUpdate()
            ->first();
    }

    public function findExpiredActiveHolds(): Collection
    {
        return Hold::where('status', StatusEnum::ACTIVE->value)
            ->where('expired_at', '<=', now())
            ->with('product')
            ->get();
    }

    public function markAsUsed(Hold $hold): void
    {
        $hold->status = StatusEnum::USED;
        $hold->save();
    }

    public function markAsExpired(Hold $hold): void
    {
        $hold->status = StatusEnum::EXPIRED;
        $hold->save();
    }

    public function isHoldUsed($holdId): bool
    {
        return Hold::where('id', $holdId)
            ->where('status', StatusEnum::USED->value)
            ->exists();
    }

    public function findActiveByIdWithLock($holdId): ?Hold
    {
        return Hold::where('id', $holdId)
            ->where('status', StatusEnum::ACTIVE->value)
            ->lockForUpdate()
            ->first();
    }
}
