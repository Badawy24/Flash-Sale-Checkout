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

class WebhookOutOfOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_arriving_before_order_creation_handles_gracefully(): void
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
            'expired_at' => Carbon::now()->addMinutes(2),
            'status' => StatusEnum::ACTIVE,
        ]);

        $product->reserved = 3;
        $product->save();

        $idempotencyKey = 'early-webhook-key';

        $webhookResponse = $this->postJson('/api/payments/webhook', [
            'order_id' => 99999,
            'status' => 'paid',
            'transaction_id' => 'txn-123',
            'idempotency_key' => $idempotencyKey,
        ]);

        $webhookResponse->assertStatus(404);
        $webhookResponse->assertJson([
            'message' => 'Order not found. Please retry.',
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'hold_id' => $hold->id,
            'quantity' => 3,
            'price' => 99.99,
            'status' => StatusEnum::PENDING,
        ]);

        $retryResponse = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'paid',
            'transaction_id' => 'txn-123',
            'idempotency_key' => $idempotencyKey,
        ]);

        $retryResponse->assertStatus(200);
        $order->refresh();
        $this->assertEquals(StatusEnum::PAID, $order->status, 'Order should be marked as PAID after retry');
        $this->assertEquals($idempotencyKey, $order->payment_webhook_reference, 'Order should store the idempotency key');
    }

    public function test_webhook_handles_order_not_found_with_idempotency_check(): void
    {
        $idempotencyKey = 'missing-order-key';

        $firstResponse = $this->postJson('/api/payments/webhook', [
            'order_id' => 99999,
            'status' => 'paid',
            'transaction_id' => 'txn-123',
            'idempotency_key' => $idempotencyKey,
        ]);

        $firstResponse->assertStatus(404);
        $firstResponse->assertJson([
            'message' => 'Order not found. Please retry.',
        ]);

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

        $retryResponse = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'paid',
            'transaction_id' => 'txn-123',
            'idempotency_key' => $idempotencyKey,
        ]);

        $retryResponse->assertStatus(200);
        $order->refresh();
        $this->assertEquals(StatusEnum::PAID, $order->status, 'Order should be marked as PAID after retry');
        $this->assertEquals($idempotencyKey, $order->payment_webhook_reference, 'Order should store the idempotency key');
    }

    public function test_webhook_handles_order_already_processed_before_webhook_arrives(): void
    {
        $product = Product::factory()->create([
            'stock' => 7,
            'reserved' => 0,
        ]);

        $user = User::factory()->create();
        $hold = Hold::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'expired_at' => Carbon::now()->addMinutes(2),
            'status' => StatusEnum::USED,
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'hold_id' => $hold->id,
            'quantity' => 3,
            'price' => 99.99,
            'status' => StatusEnum::PAID,
            'payment_webhook_reference' => 'previous-webhook-key',
        ]);

        $lateWebhookResponse = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'paid',
            'transaction_id' => 'txn-late',
            'idempotency_key' => 'late-webhook-key',
        ]);

        $lateWebhookResponse->assertStatus(200);
        $lateWebhookResponse->assertJson([
            'message' => 'Order already processed',
        ]);

        $order->refresh();
        $this->assertEquals(StatusEnum::PAID, $order->status, 'Order status should remain PAID');

        $product->refresh();
        $this->assertEquals(7, $product->stock, 'Stock should remain unchanged');
    }

    public function test_multiple_webhooks_arriving_out_of_order(): void
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

        $idempotencyKey1 = 'webhook-key-1';
        $idempotencyKey2 = 'webhook-key-2';

        $response1 = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'paid',
            'transaction_id' => 'txn-1',
            'idempotency_key' => $idempotencyKey1,
        ]);

        $response1->assertStatus(200);
        $order->refresh();
        $this->assertEquals(StatusEnum::PAID, $order->status, 'Order should be marked as PAID');

        $response2 = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'paid',
            'transaction_id' => 'txn-2',
            'idempotency_key' => $idempotencyKey2,
        ]);

        $response2->assertStatus(200);
        $response2->assertJson([
            'message' => 'Order already processed',
        ]);

        $order->refresh();
        $this->assertEquals(StatusEnum::PAID, $order->status, 'Order status should remain PAID');
    }

    public function test_webhook_for_already_cancelled_order(): void
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
            'expired_at' => Carbon::now()->addMinutes(2),
            'status' => StatusEnum::EXPIRED,
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'hold_id' => $hold->id,
            'quantity' => 3,
            'price' => 99.99,
            'status' => StatusEnum::CANCELLED,
            'payment_webhook_reference' => 'previous-failed-key',
        ]);

        $lateWebhookResponse = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'paid',
            'transaction_id' => 'txn-late',
            'idempotency_key' => 'late-paid-key',
        ]);

        $lateWebhookResponse->assertStatus(200);
        $lateWebhookResponse->assertJson([
            'message' => 'Order already processed',
        ]);

        $order->refresh();
        $this->assertEquals(StatusEnum::CANCELLED, $order->status, 'Order status should remain CANCELLED');
    }

    public function test_webhook_retry_after_order_creation_preserves_idempotency(): void
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

        $idempotencyKey = 'retry-idempotency-key';
        $initialStock = $product->stock;

        $firstAttempt = $this->postJson('/api/payments/webhook', [
            'order_id' => 99999,
            'status' => 'paid',
            'transaction_id' => 'txn-retry',
            'idempotency_key' => $idempotencyKey,
        ]);

        $firstAttempt->assertStatus(404);

        $order = Order::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'hold_id' => $hold->id,
            'quantity' => 3,
            'price' => 99.99,
            'status' => StatusEnum::PENDING,
        ]);

        $retryAttempt = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'paid',
            'transaction_id' => 'txn-retry',
            'idempotency_key' => $idempotencyKey,
        ]);

        $retryAttempt->assertStatus(200);
        $order->refresh();
        $this->assertEquals(StatusEnum::PAID, $order->status, 'Order should be marked as PAID');
        $this->assertEquals($idempotencyKey, $order->payment_webhook_reference, 'Idempotency key should be stored');

        $secondRetry = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'paid',
            'transaction_id' => 'txn-retry-2',
            'idempotency_key' => $idempotencyKey,
        ]);

        $secondRetry->assertStatus(200);
        $secondRetry->assertJson([
            'message' => 'Webhook already processed',
        ]);

        $product->refresh();
        $expectedStock = $initialStock - $order->quantity;
        $this->assertEquals($expectedStock, $product->stock, 'Stock should be reduced only once');
    }
}
