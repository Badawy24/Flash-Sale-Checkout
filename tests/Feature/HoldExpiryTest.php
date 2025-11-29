<?php

namespace Tests\Feature;

use App\Enums\StatusEnum;
use App\Jobs\ExpireHoldsJob;
use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoldExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_hold_releases_stock_and_returns_availability(): void
    {
        $product = Product::factory()->create([
            'stock' => 10,
            'reserved' => 0,
        ]);

        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'quantity' => 3,
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $holdResponse->assertStatus(201)->assertJsonStructure(['hold_id', 'expires_at']);
        $holdId = $holdResponse->json('hold_id');

        $product->refresh();
        $this->assertEquals(3, $product->reserved, 'Stock should be reserved');
        $this->assertEquals(7, $product->stock - $product->reserved, 'Available stock should be 7');

        $hold = Hold::find($holdId);
        $hold->expired_at = Carbon::now()->subMinute();
        $hold->save();

        (new ExpireHoldsJob())->handle(
            app(\App\Repositories\HoldRepository::class),
            app(\App\Repositories\OrderRepository::class),
            app(\App\Repositories\ProductRepository::class)
        );

        $hold->refresh();
        $this->assertEquals(StatusEnum::EXPIRED, $hold->status, 'Hold should be marked as EXPIRED');

        $product->refresh();
        $this->assertEquals(0, $product->reserved, 'Reserved stock should be 0 after expiry');
        $this->assertEquals(10, $product->stock - $product->reserved, 'Available stock should be 10 after expiry');

        $productResponse = $this->getJson("/api/products/{$product->id}");
        $productResponse->assertStatus(200);
        $this->assertEquals(10, $productResponse->json('available_stock'), 'Product API should show 10 available stock');
    }

    public function test_expired_hold_with_existing_order_is_marked_as_used_not_expired(): void
    {
        $product = Product::factory()->create([
            'stock' => 10,
            'reserved' => 3,
        ]);

        $user = User::factory()->create();

        $hold = Hold::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'expired_at' => Carbon::now()->subMinute(),
            'status' => StatusEnum::ACTIVE,
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'hold_id' => $hold->id,
            'quantity' => 3,
            'price' => 99.99,
            'status' => StatusEnum::PENDING,
        ]);

        (new ExpireHoldsJob())->handle(
            app(\App\Repositories\HoldRepository::class),
            app(\App\Repositories\OrderRepository::class),
            app(\App\Repositories\ProductRepository::class)
        );

        $hold->refresh();
        $this->assertEquals(StatusEnum::USED, $hold->status,
            'Hold with existing order should be marked as USED, not EXPIRED');

        $product->refresh();
        $this->assertEquals(3, $product->reserved, 'Stock should remain reserved when order exists');
    }

    public function test_multiple_expired_holds_release_stock_correctly_in_batch(): void
    {
        $product = Product::factory()->create([
            'stock' => 10,
            'reserved' => 0,
        ]);

        $users = User::factory()->count(3)->create();

        foreach ($users as $user) {
            Hold::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'quantity' => 2,
                'expired_at' => Carbon::now()->subMinute(),
                'status' => StatusEnum::ACTIVE,
            ]);
        }

        $product->reserved = 6;
        $product->save();

        (new ExpireHoldsJob())->handle(
            app(\App\Repositories\HoldRepository::class),
            app(\App\Repositories\OrderRepository::class),
            app(\App\Repositories\ProductRepository::class)
        );

        $expiredHolds = Hold::where('status', StatusEnum::EXPIRED)->count();
        $this->assertEquals(3, $expiredHolds, 'All 3 holds should be marked as EXPIRED');

        $product->refresh();
        $this->assertEquals(0, $product->reserved, 'All reserved stock should be released');
        $this->assertEquals(10, $product->stock - $product->reserved, 'Available stock should be 10');
    }

    public function test_mixed_expired_holds_with_and_without_orders(): void
    {
        $product = Product::factory()->create([
            'stock' => 20,
            'reserved' => 0,
        ]);

        $users = User::factory()->count(4)->create();

        $holds = [];
        foreach ($users as $index => $user) {
            $hold = Hold::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'quantity' => 3,
                'expired_at' => Carbon::now()->subMinute(),
                'status' => StatusEnum::ACTIVE,
            ]);
            $holds[] = $hold;

            if ($index < 2) {
                Order::create([
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'hold_id' => $hold->id,
                    'quantity' => 3,
                    'price' => 99.99,
                    'status' => StatusEnum::PENDING,
                ]);
            }
        }

        $product->reserved = 12;
        $product->save();

        (new ExpireHoldsJob())->handle(
            app(\App\Repositories\HoldRepository::class),
            app(\App\Repositories\OrderRepository::class),
            app(\App\Repositories\ProductRepository::class)
        );

        $expiredHolds = Hold::where('status', StatusEnum::EXPIRED)->count();
        $usedHolds = Hold::where('status', StatusEnum::USED)->count();

        $this->assertEquals(2, $expiredHolds, '2 holds without orders should be EXPIRED');
        $this->assertEquals(2, $usedHolds, '2 holds with orders should be USED');

        $product->refresh();
        $this->assertEquals(6, $product->reserved, 'Stock for holds with orders should remain reserved');
        $this->assertEquals(14, $product->stock - $product->reserved, 'Available stock should be 14 (20 - 6 reserved)');
    }

    public function test_non_expired_holds_are_not_affected_by_expiry_job(): void
    {
        $product = Product::factory()->create([
            'stock' => 10,
            'reserved' => 0,
        ]);

        $users = User::factory()->count(3)->create();

        $expiredHold1 = Hold::create([
            'user_id' => $users[0]->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'expired_at' => Carbon::now()->subMinute(),
            'status' => StatusEnum::ACTIVE,
        ]);

        $expiredHold2 = Hold::create([
            'user_id' => $users[1]->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'expired_at' => Carbon::now()->subMinute(),
            'status' => StatusEnum::ACTIVE,
        ]);

        $activeHold = Hold::create([
            'user_id' => $users[2]->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'expired_at' => Carbon::now()->addMinutes(5),
            'status' => StatusEnum::ACTIVE,
        ]);

        $product->reserved = 7;
        $product->save();

        (new ExpireHoldsJob())->handle(
            app(\App\Repositories\HoldRepository::class),
            app(\App\Repositories\OrderRepository::class),
            app(\App\Repositories\ProductRepository::class)
        );

        $expiredHold1->refresh();
        $expiredHold2->refresh();
        $this->assertEquals(StatusEnum::EXPIRED, $expiredHold1->status);
        $this->assertEquals(StatusEnum::EXPIRED, $expiredHold2->status);

        $activeHold->refresh();
        $this->assertEquals(StatusEnum::ACTIVE, $activeHold->status, 'Non-expired hold should remain ACTIVE');

        $product->refresh();
        $this->assertEquals(2, $product->reserved, 'Stock for active hold should remain reserved');
        $this->assertEquals(8, $product->stock - $product->reserved, 'Available stock should be 8');
    }

    public function test_already_expired_holds_are_skipped_by_expiry_job(): void
    {
        $product = Product::factory()->create([
            'stock' => 10,
            'reserved' => 0,
        ]);

        $user = User::factory()->create();

        $hold = Hold::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'expired_at' => Carbon::now()->subMinute(),
            'status' => StatusEnum::EXPIRED,
        ]);

        $product->reserved = 3;
        $product->save();

        (new ExpireHoldsJob())->handle(
            app(\App\Repositories\HoldRepository::class),
            app(\App\Repositories\OrderRepository::class),
            app(\App\Repositories\ProductRepository::class)
        );

        $hold->refresh();
        $this->assertEquals(StatusEnum::EXPIRED, $hold->status, 'Already expired hold should remain EXPIRED');
    }
}
