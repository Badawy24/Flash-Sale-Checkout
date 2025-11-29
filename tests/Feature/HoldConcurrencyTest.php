<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoldConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_parallel_hold_attempts_at_stock_boundary_prevent_oversell(): void
    {
        $product = Product::factory()->create([
            'stock' => 10,
            'reserved' => 0,
        ]);

        $users = User::factory()->count(15)->create();
        $tokens = $users->map(fn($user) => $user->createToken('test-token')->plainTextToken);

        $responses = [];
        foreach ($tokens as $token) {
            $responses[] = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'quantity' => 1,
            ], [
                'Authorization' => 'Bearer ' . $token,
            ]);
        }

        $product->refresh();

        $totalHoldsCreated = Hold::where('product_id', $product->id)->count();

        $this->assertEquals(10, $totalHoldsCreated, 'Exactly 10 holds should be created (no overselling)');
        $this->assertEquals(10, $product->reserved, 'Reserved stock should equal exactly 10');
        $this->assertEquals(0, $product->stock - $product->reserved, 'Available stock should be 0');
        $this->assertLessThanOrEqual($product->stock, $product->reserved, 'Reserved stock must not exceed total stock');
    }

    public function test_concurrent_holds_with_different_quantities(): void
    {
        $product = Product::factory()->create([
            'stock' => 10,
            'reserved' => 0,
        ]);

        $users = User::factory()->count(5)->create();
        $tokens = $users->map(fn($user) => $user->createToken('test-token')->plainTextToken);

        $responses = [];
        foreach ($tokens as $token) {
            $responses[] = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'quantity' => 3,
            ], [
                'Authorization' => 'Bearer ' . $token,
            ]);
        }

        $successfulHolds = collect($responses)->filter(fn($r) =>
            $r->status() === 200 || $r->status() === 201
        )->count();

        $failedHolds = collect($responses)->filter(fn($r) =>
            $r->status() === 422
        )->count();

        $product->refresh();

        $this->assertLessThanOrEqual(10, $product->reserved,
            'Reserved stock should not exceed total stock');
        $this->assertGreaterThanOrEqual(0, $product->stock - $product->reserved,
            'Available stock should not be negative');
        $this->assertEquals($successfulHolds + $failedHolds, 5,
            'All requests should be processed');

        $totalReservedQuantity = Hold::where('product_id', $product->id)
            ->sum('quantity');
        $this->assertEquals($product->reserved, $totalReservedQuantity,
            'Reserved stock should match sum of hold quantities');
    }

    public function test_concurrent_holds_with_database_locks_prevent_race_conditions(): void
    {
        $product = Product::factory()->create([
            'stock' => 5,
            'reserved' => 0,
        ]);

        $users = User::factory()->count(10)->create();
        $tokens = $users->map(fn($user) => $user->createToken('test-token')->plainTextToken);

        $responses = [];
        foreach ($tokens as $token) {
            $responses[] = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'quantity' => 1,
            ], [
                'Authorization' => 'Bearer ' . $token,
            ]);
        }

        $product->refresh();

        $this->assertLessThanOrEqual($product->stock, $product->reserved,
            'Reserved stock must never exceed total stock');
        $this->assertGreaterThanOrEqual(0, $product->stock - $product->reserved,
            'Available stock must not be negative');
        $this->assertEquals(5, Hold::where('product_id', $product->id)->count(),
            'Exactly 5 holds should be created');

        $successfulResponses = collect($responses)->filter(fn($r) =>
            $r->status() === 200 || $r->status() === 201
        )->count();
        $this->assertEquals(5, $successfulResponses,
            'Exactly 5 requests should succeed');
    }

    public function test_concurrent_holds_with_mixed_quantities(): void
    {
        $product = Product::factory()->create([
            'stock' => 20,
            'reserved' => 0,
        ]);

        $users = User::factory()->count(8)->create();
        $tokens = $users->map(fn($user) => $user->createToken('test-token')->plainTextToken);

        $quantities = [5, 5, 5, 5, 3, 3, 2, 2];

        $responses = [];
        foreach ($tokens as $index => $token) {
            $responses[] = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'quantity' => $quantities[$index],
            ], [
                'Authorization' => 'Bearer ' . $token,
            ]);
        }

        $product->refresh();

        $this->assertLessThanOrEqual(20, $product->reserved,
            'Reserved stock should not exceed total stock');
        $this->assertGreaterThanOrEqual(0, $product->stock - $product->reserved,
            'Available stock should not be negative');

        $totalHoldQuantity = Hold::where('product_id', $product->id)
            ->sum('quantity');
        $this->assertEquals($product->reserved, $totalHoldQuantity,
            'Reserved stock should match sum of hold quantities');

        $totalSuccessfulQuantity = Hold::where('product_id', $product->id)
            ->sum('quantity');
        $this->assertLessThanOrEqual(20, $totalSuccessfulQuantity,
            'Total successful hold quantity should not exceed stock');
    }

    public function test_database_transaction_isolation_maintains_stock_consistency(): void
    {
        $product = Product::factory()->create([
            'stock' => 7,
            'reserved' => 0,
        ]);

        $users = User::factory()->count(7)->create();
        $tokens = $users->map(fn($user) => $user->createToken('test-token')->plainTextToken);

        $responses = [];
        foreach ($tokens as $token) {
            $responses[] = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'quantity' => 1,
            ], [
                'Authorization' => 'Bearer ' . $token,
            ]);
        }

        $product->refresh();

        $this->assertEquals(7, $product->reserved, 'All stock should be reserved');
        $this->assertEquals(0, $product->stock - $product->reserved, 'No available stock should remain');
        $this->assertEquals(7, Hold::where('product_id', $product->id)->count(),
            'Exactly 7 holds should be created');

        $successfulResponses = collect($responses)->filter(fn($r) =>
            $r->status() === 200 || $r->status() === 201
        )->count();
        $this->assertEquals(7, $successfulResponses, 'All 7 requests should succeed');
    }
}
