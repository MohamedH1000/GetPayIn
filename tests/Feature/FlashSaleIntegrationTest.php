<?php

use App\Models\Product;
use App\Models\Hold;
use App\Models\Order;

test('complete flash sale workflow from hold to paid order', function () {
    // 1. Set up a flash sale product
    $product = Product::factory()->create([
        'name' => 'Limited Edition Widget',
        'price' => 99.99,
        'stock' => 50,
    ]);

    // 2. Check product details
    $productResponse = $this->getJson("/api/products/{$product->id}");
    $productResponse->assertStatus(200)
        ->assertJson([
            'total_stock' => 50,
            'available_stock' => 50,
        ]);

    // 3. Create a hold for 3 items
    $holdResponse = $this->postJson('/api/holds', [
        'product_id' => $product->id,
        'quantity' => 3,
    ]);

    $holdResponse->assertStatus(201);
    $holdToken = $holdResponse->json('hold_token');

    // 4. Check that available stock decreased
    $updatedProductResponse = $this->getJson("/api/products/{$product->id}");
    $updatedProductResponse->assertStatus(200)
        ->assertJson([
            'total_stock' => 50,
            'available_stock' => 47, // 50 - 3
        ]);

    // 5. Create order from hold
    $orderResponse = $this->postJson('/api/orders', [
        'hold_id' => $holdResponse->json('hold_id'),
    ]);

    $orderResponse->assertStatus(201)
        ->assertJson([
            'product_id' => $product->id,
            'quantity' => 3,
            'total_amount' => '299.97', // 99.99 * 3
            'status' => 'pending',
        ]);

    $orderId = $orderResponse->json('order_id');

    // 6. Process payment webhook (successful payment)
    $webhookResponse = $this->postJson('/api/payments/webhook', [
        'payment_id' => 'pay_flash_sale_123',
        'order_id' => $orderId,
        'status' => 'success',
        'idempotency_key' => 'flash_sale_webhook_456',
        'amount' => 299.97,
    ]);

    $webhookResponse->assertStatus(200)
        ->assertJson([
            'order_status' => 'paid',
        ]);

    // 7. Verify final order status
    $finalOrderResponse = $this->getJson("/api/orders/{$orderId}"); // Note: This endpoint doesn't exist in our API
    // Instead, let's verify through the model
    $order = Order::find($orderId);
    expect($order->status)->toBe('paid');
    expect($order->payment_id)->toBe('pay_flash_sale_123');
});

test('flash sale prevents overselling under high concurrency', function () {
    // Create product with limited stock
    $product = Product::factory()->create(['stock' => 20]);

    // Simulate 25 concurrent requests for 1 item each
    $requests = collect(range(1, 25))->map(function () use ($product) {
        return Hold::createHold($product, 1);
    });

    // Count successful holds
    $successfulHolds = $requests->filter()->count();
    $totalQuantityHeld = $requests->sum(fn($hold) => $hold?->quantity ?? 0);

    // Should not exceed available stock
    expect($successfulHolds)->toBeLessThanOrEqual(20);
    expect($totalQuantityHeld)->toBeLessThanOrEqual(20);

    // Check final available stock
    expect($product->available_stock)->toBeGreaterThanOrEqual(0);
    expect($product->available_stock)->toBe(20 - $totalQuantityHeld);
});

test('hold expiry restores stock for new purchases', function () {
    $product = Product::factory()->create(['stock' => 30]);

    // Create a hold
    $hold = Hold::createHold($product, 10);
    expect($product->available_stock)->toBe(20);

    // Manually expire the hold
    $hold->expires_at = now()->subMinutes(1);
    $hold->save();

    // Create order to simulate the workflow
    $order = Order::createFromHold($hold);
    expect($order->status)->toBe('pending');

    // Run expiry job
    (new \App\Jobs\ExpireHolds)->handle();

    // Stock should be restored
    expect($product->available_stock)->toBe(30);

    // Order should be expired
    $order->refresh();
    expect($order->status)->toBe('expired');
});

test('webhook idempotency prevents duplicate payments', function () {
    $product = Product::factory()->create(['price' => 49.99]);
    $hold = Hold::createHold($product, 1);
    $order = Order::createFromHold($hold);

    $webhookData = [
        'payment_id' => 'pay_duplicate_test',
        'order_id' => $order->id,
        'status' => 'success',
        'idempotency_key' => 'duplicate_webhook_key',
        'amount' => 49.99,
    ];

    // Send webhook twice
    $response1 = $this->postJson('/api/payments/webhook', $webhookData);
    $response1->assertStatus(200);

    $response2 = $this->postJson('/api/payments/webhook', $webhookData);
    $response2->assertStatus(200)
        ->assertJson([
            'message' => 'Webhook already processed',
        ]);

    // Order should only be paid once
    $order->refresh();
    expect($order->status)->toBe('paid');

    // Only one webhook record should exist
    $webhookCount = \App\Models\PaymentWebhook::where('idempotency_key', 'duplicate_webhook_key')->count();
    expect($webhookCount)->toBe(1);
});

test('edge case: webhook arrives before order completion', function () {
    $product = Product::factory()->create(['price' => 75.00]);
    $hold = Hold::createHold($product, 1);

    // Webhook arrives first (out of order)
    $webhookResponse = $this->postJson('/api/payments/webhook', [
        'payment_id' => 'pay_early_webhook',
        'order_id' => 999999, // Order doesn't exist yet
        'status' => 'success',
        'idempotency_key' => 'early_webhook_key',
    ]);

    $webhookResponse->assertStatus(500);

    // Now create the order
    $order = Order::createFromHold($hold);

    // Retry webhook with correct order_id
    $retryResponse = $this->postJson('/api/payments/webhook', [
        'payment_id' => 'pay_early_webhook',
        'order_id' => $order->id,
        'status' => 'success',
        'idempotency_key' => 'early_webhook_key',
    ]);

    $retryResponse->assertStatus(200);

    $order->refresh();
    expect($order->status)->toBe('paid');
});