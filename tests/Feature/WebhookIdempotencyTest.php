<?php

namespace Tests\Feature;

use App\Enums\StatusEnum;
use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_with_same_idempotency_key_is_processed_only_once(): void
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
            'expired_at' => Carbon::now()->addMinutes(2),
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

        $idempotencyKey = 'test-idempotency-key-123';
        $initialStock = $product->stock;
        $initialReserved = $product->reserved;

        $firstResponse = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'paid',
            'transaction_id' => 'txn-123',
            'idempotency_key' => $idempotencyKey,
        ]);

        $firstResponse->assertStatus(200);
        $order->refresh();
        $this->assertEquals(StatusEnum::PAID, $order->status, 'Order should be marked as PAID after first webhook');
        $this->assertEquals($idempotencyKey, $order->payment_webhook_reference, 'Order should store the idempotency key');

        $product->refresh();
        $stockAfterFirst = $product->stock;
        $reservedAfterFirst = $product->reserved;
        $this->assertLessThan($initialStock, $stockAfterFirst, 'Stock should be reduced after payment');
        $this->assertLessThan($initialReserved, $reservedAfterFirst, 'Reserved stock should be reduced after payment');

        $secondResponse = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'paid',
            'transaction_id' => 'txn-456',
            'idempotency_key' => $idempotencyKey,
        ]);

        $secondResponse->assertStatus(200);
        $secondResponse->assertJson([
            'message' => 'Webhook already processed',
        ]);

        $order->refresh();
        $this->assertEquals(StatusEnum::PAID, $order->status, 'Order status should remain PAID');
        $this->assertEquals($idempotencyKey, $order->payment_webhook_reference, 'Idempotency key should remain unchanged');

        $product->refresh();
        $this->assertEquals($stockAfterFirst, $product->stock, 'Stock should not be reduced again');
        $this->assertEquals($reservedAfterFirst, $product->reserved, 'Reserved stock should not be reduced again');
    }

    public function test_webhook_with_different_idempotency_keys_processes_separately(): void
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
            'expired_at' => Carbon::now()->addMinutes(2),
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

        $response1 = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'paid',
            'transaction_id' => 'txn-1',
            'idempotency_key' => 'key-1',
        ]);

        $response1->assertStatus(200);
        $order->refresh();
        $this->assertEquals(StatusEnum::PAID, $order->status, 'Order should be marked as PAID');
        $this->assertEquals('key-1', $order->payment_webhook_reference, 'Order should store first idempotency key');

        $response2 = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'paid',
            'transaction_id' => 'txn-2',
            'idempotency_key' => 'key-2',
        ]);

        $response2->assertStatus(200);
        $response2->assertJson([
            'message' => 'Order already processed',
        ]);

        $order->refresh();
        $this->assertEquals(StatusEnum::PAID, $order->status, 'Order status should remain PAID');
    }

    public function test_webhook_idempotency_with_failed_payment(): void
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
            'expired_at' => Carbon::now()->addMinutes(2),
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

        $idempotencyKey = 'failed-payment-key';

        $firstResponse = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'failed',
            'transaction_id' => 'txn-fail-1',
            'idempotency_key' => $idempotencyKey,
        ]);

        $firstResponse->assertStatus(200);
        $order->refresh();
        $this->assertEquals(StatusEnum::CANCELLED, $order->status, 'Order should be marked as CANCELLED after failed payment');

        $product->refresh();
        $initialReserved = $product->reserved;
        $this->assertLessThan(3, $initialReserved, 'Reserved stock should be reduced after failed payment');

        $hold->refresh();
        $this->assertEquals(StatusEnum::EXPIRED, $hold->status, 'Hold should be marked as EXPIRED after failed payment');

        $secondResponse = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'failed',
            'transaction_id' => 'txn-fail-2',
            'idempotency_key' => $idempotencyKey,
        ]);

        $secondResponse->assertStatus(200);
        $secondResponse->assertJson([
            'message' => 'Webhook already processed',
        ]);

        $order->refresh();
        $this->assertEquals(StatusEnum::CANCELLED, $order->status, 'Order status should remain CANCELLED');

        $product->refresh();
        $this->assertEquals($initialReserved, $product->reserved, 'Reserved stock should not be reduced again');
    }

    public function test_idempotency_key_uniqueness_across_different_orders(): void
    {
        $product1 = Product::factory()->create([
            'stock' => 10,
            'reserved' => 2,
        ]);

        $product2 = Product::factory()->create([
            'stock' => 10,
            'reserved' => 2,
        ]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $hold1 = Hold::create([
            'user_id' => $user1->id,
            'product_id' => $product1->id,
            'quantity' => 2,
            'expired_at' => Carbon::now()->addMinutes(2),
            'status' => StatusEnum::ACTIVE,
        ]);

        $hold2 = Hold::create([
            'user_id' => $user2->id,
            'product_id' => $product2->id,
            'quantity' => 2,
            'expired_at' => Carbon::now()->addMinutes(2),
            'status' => StatusEnum::ACTIVE,
        ]);

        $order1 = Order::create([
            'user_id' => $user1->id,
            'product_id' => $product1->id,
            'hold_id' => $hold1->id,
            'quantity' => 2,
            'price' => 99.99,
            'status' => StatusEnum::PENDING,
        ]);

        $order2 = Order::create([
            'user_id' => $user2->id,
            'product_id' => $product2->id,
            'hold_id' => $hold2->id,
            'quantity' => 2,
            'price' => 199.99,
            'status' => StatusEnum::PENDING,
        ]);

        $idempotencyKey = 'shared-key-123';

        $response1 = $this->postJson('/api/payments/webhook', [
            'order_id' => $order1->id,
            'status' => 'paid',
            'transaction_id' => 'txn-1',
            'idempotency_key' => $idempotencyKey,
        ]);

        $response1->assertStatus(200);
        $order1->refresh();
        $this->assertEquals(StatusEnum::PAID, $order1->status);

        $response2 = $this->postJson('/api/payments/webhook', [
            'order_id' => $order2->id,
            'status' => 'paid',
            'transaction_id' => 'txn-2',
            'idempotency_key' => $idempotencyKey,
        ]);

        $response2->assertStatus(200);
    }

    public function test_rapid_duplicate_webhook_requests_are_idempotent(): void
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
            'expired_at' => Carbon::now()->addMinutes(2),
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

        $idempotencyKey = 'rapid-duplicate-key';
        $initialStock = $product->stock;

        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->postJson('/api/payments/webhook', [
                'order_id' => $order->id,
                'status' => 'paid',
                'transaction_id' => "txn-rapid-{$i}",
                'idempotency_key' => $idempotencyKey,
            ]);
        }

        $firstResponse = $responses[0];
        $firstResponse->assertStatus(200);

        $processedCount = collect($responses)->filter(fn($r) =>
            $r->json('message') === 'Webhook processed'
        )->count();

        $alreadyProcessedCount = collect($responses)->filter(fn($r) =>
            $r->json('message') === 'Webhook already processed'
        )->count();

        $this->assertEquals(1, $processedCount, 'Only one webhook should be processed');
        $this->assertEquals(4, $alreadyProcessedCount, 'Four webhooks should return already processed');

        $order->refresh();
        $this->assertEquals(StatusEnum::PAID, $order->status, 'Order should be PAID');

        $product->refresh();
        $expectedStock = $initialStock - $order->quantity;
        $this->assertEquals($expectedStock, $product->stock, 'Stock should be reduced only once, not 5 times');
    }
}
