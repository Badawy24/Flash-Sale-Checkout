# Flash-Sale Checkout API

A high-concurrency flash-sale checkout system built with Laravel 12, designed to handle burst traffic without overselling.

## Setup and Running

### Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
```

### Database Configuration

Configure `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=flash_sale
DB_USERNAME=root
DB_PASSWORD=
```

### Run Migrations and Seed

```bash
php artisan migrate
php artisan db:seed
```

### Start Scheduler (Required)

The scheduler must run for hold expiry to work:

```bash
php artisan schedule:work
```

### Start Server

```bash
php artisan serve
```

## Running Tests

### Run All Tests

```bash
php artisan test
```

### Test Coverage

- **HoldConcurrencyTest**: Parallel hold attempts prevent overselling
- **HoldExpiryTest**: Expired holds release stock automatically
- **WebhookIdempotencyTest**: Same idempotency key processed only once
- **WebhookOutOfOrderTest**: Webhook arriving before order creation handled gracefully

### Run Specific Tests

```bash
php artisan test --filter HoldConcurrencyTest
php artisan test --filter HoldExpiryTest
php artisan test --filter WebhookIdempotencyTest
php artisan test --filter WebhookOutOfOrderTest
```

## Assumptions and Invariants

### Assumptions

The system operates under the following assumptions:

- **Product Stock**: Product stock is finite and cannot be negative. Stock is reduced only when an order is successfully paid.
- **Hold Expiry**: Holds expire after exactly 2 minutes from creation. Expired holds automatically release reserved stock.
- **Hold Usage**: Each hold can only be used once to create an order. Once used, the hold status changes to `USED` and cannot be reused.
- **Webhook Idempotency**: Webhook idempotency keys are unique per payment attempt. The same key may arrive multiple times (retries), but should be processed only once.
- **Payment Provider**: Payment provider may retry webhooks on failure or timeout. Webhooks may arrive out of order (before order creation response).
- **Concurrency**: Multiple users may attempt to create holds simultaneously. The system must prevent overselling under high concurrency.

### Invariants

The following invariants are enforced throughout the system:

- **Stock Consistency**:
  - `available_stock = stock - reserved` (always >= 0)
  - `reserved <= stock` (reserved stock never exceeds total stock)
  - Stock is only reduced when order status changes to `PAID`

- **Hold Status Transitions**:
  - `ACTIVE` → `USED` (when order is created)
  - `ACTIVE` → `EXPIRED` (when hold expires without order)
  - Transitions are one-way (no rollback)

- **Order Status Transitions**:
  - `PENDING` → `PAID` (when payment succeeds)
  - `PENDING` → `CANCELLED` (when payment fails)
  - Transitions are one-way (no rollback)

- **Stock Operations**:
  - Stock is reserved when hold is created
  - Reserved stock is released when hold expires (if no order exists)
  - Actual stock is reduced only when order is paid
  - Reserved stock is reduced when order is paid or cancelled

- **Idempotency**:
  - Same webhook idempotency key processed only once
  - Order status remains consistent across duplicate webhook calls
  - Stock operations are idempotent (no double reduction)

## API Endpoints

### Postman Collection

You can import the complete API collection with all endpoints and examples:

[**Import Postman Collection**](https://badawy-6655723.postman.co/workspace/Badawy's-Workspace~cee17264-cf90-48d3-86dd-7f065636073a/collection/45898825-be3b8966-e3b7-4465-b2a3-70778a2bfd47?action=share&creator=45898825&active-environment=45898825-d6870a2f-3d92-4e4a-abef-5a74faa78d14&live=au8um37ci8)

### Authentication

Login: `POST /api/login` with `{ "email": "badawy@mail.com", "password": "123456789" }`

Use token: `Authorization: Bearer {token}`

### 1. Get Product

```http
GET /api/products/{id}
```

Response:
```json
{
  "id": 1,
  "name": "Mobile Phone",
  "price": "1000.00",
  "stock": 50,
  "reserved": 5,
  "available_stock": 45
}
```

### 2. Create Hold

```http
POST /api/holds
Authorization: Bearer {token}

{
  "product_id": 1,
  "qty": 2
}
```

Response (201):
```json
{
  "hold_id": 123,
  "expires_at": "2024-01-01 12:02:00"
}
```

### 3. Create Order

```http
POST /api/orders
Authorization: Bearer {token}

{
  "hold_id": 123
}
```

Response (201):
```json
{
  "order_id": 456,
  "payment_url": "https://fake-payment.com/pay/456"
}
```

### 4. Payment Webhook

```http
POST /api/payments/webhook

{
  "order_id": 456,
  "status": "paid",
  "transaction_id": "txn-789",
  "idempotency_key": "unique-key-123"
}
```

Status: `paid` or `failed`

## Logs and Metrics

### Log Location

All logs: `storage/logs/laravel.log`

### Viewing Logs

**Real-time:**
```bash
php artisan pail
```

**View log file:**
```bash
tail -f storage/logs/laravel.log
```

### Metrics Location

All metrics are logged to `storage/logs/laravel.log` with structured JSON format. Here's where to find specific metrics:

#### Lock Contention Metrics

**When to check:** During high concurrency periods

**Search for:**
```bash
grep "Product lock acquisition took longer than expected" storage/logs/laravel.log
```

**What it shows:**
- Product ID
- Lock acquisition duration (milliseconds)
- Indicates potential database contention

**Example log entry:**
```json
{
  "message": "Product lock acquisition took longer than expected",
  "product_id": 1,
  "duration_ms": 150.25
}
```

#### Webhook Deduplication Metrics

**When to check:** When monitoring webhook retries

**Search for:**
```bash
grep "Webhook deduplication" storage/logs/laravel.log
```

**What it shows:**
- Idempotency key
- Order ID
- Order status
- Indicates duplicate webhook detection

**Example log entry:**
```json
{
  "message": "Webhook deduplication: already processed",
  "idempotency_key": "unique-key-123",
  "order_id": 456,
  "order_status": "paid"
}
```

#### Hold Expiry Metrics

**When to check:** After hold expiry job runs (every minute)

**Search for:**
```bash
grep "Hold expiry job completed" storage/logs/laravel.log
```

**What it shows:**
- Total expired holds found
- Count of holds marked as expired
- Count of holds marked as used (had orders)
- Count of skipped holds (already processed)

**Example log entry:**
```json
{
  "message": "Hold expiry job completed",
  "total_found": 5,
  "expired": 3,
  "marked_as_used": 2,
  "skipped": 0
}
```

#### Stock Operations Metrics

**When to check:** When monitoring stock changes

**Search for:**
```bash
grep -E "(Stock reserved|Stock released|Order fulfilled)" storage/logs/laravel.log
```

**What it shows:**
- Product ID
- Quantity changed
- Current reserved stock
- Current available stock

#### Request Duration Metrics

**When to check:** Performance monitoring

**Search for:**
```bash
grep "duration_ms" storage/logs/laravel.log
```

**What it shows:**
- Operation duration in milliseconds
- Helps identify slow operations
- Available for: Hold creation, Order creation, Webhook processing

### Metrics Summary

| Metric Type | Log Message | Location |
|------------|-------------|----------|
| Lock Contention | "Product lock acquisition took longer than expected" | `storage/logs/laravel.log` |
| Webhook Deduplication | "Webhook deduplication: already processed" | `storage/logs/laravel.log` |
| Hold Expiry | "Hold expiry job completed" | `storage/logs/laravel.log` |
| Stock Operations | "Stock reserved/released/fulfilled" | `storage/logs/laravel.log` |
| Request Duration | "duration_ms" in various logs | `storage/logs/laravel.log` |
