<?php

use App\Models\Product;
use App\Models\Hold;
use App\Models\Order;
use App\Models\PaymentWebhook;

test('webhook processes successful payment correctly', function () {
    $product = Product::factory()->create(['price' => 29.99]);
    $hold = Hold::createHold($product, 2);
    $order = Order::createFromHold($hold);

    $response = $this->postJson('/api/payments/webhook', [
        'payment_id' => 'pay_123456',
        'order_id' => $order->id,
        'status' => 'success',
        'idempotency_key' => 'webhook_key_123',
        'amount' => 59.98,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'idempotency_key',
            'payment_id',
            'order_status',
        ]);

    expect($response->json('order_status'))->toBe('paid');

    // Verify order was updated
    $order->refresh();
    expect($order->status)->toBe('paid');
    expect($order->payment_id)->toBe('pay_123456');
    expect($order->idempotency_key)->toBe('webhook_key_123');

    // Verify webhook was recorded
    $webhook = PaymentWebhook::where('idempotency_key', 'webhook_key_123')->first();
    expect($webhook)->not->toBeNull();
    expect($webhook->status)->toBe('success');
});

test('webhook processes failed payment correctly', function () {
    $product = Product::factory()->create(['price' => 15.00]);
    $hold = Hold::createHold($product, 1);
    $order = Order::createFromHold($hold);

    $response = $this->postJson('/api/payments/webhook', [
        'payment_id' => 'pay_failed_789',
        'order_id' => $order->id,
        'status' => 'failure',
        'idempotency_key' => 'webhook_key_456',
    ]);

    $response->assertStatus(200);

    // Verify order was cancelled
    $order->refresh();
    expect($order->status)->toBe('cancelled');

    // Verify webhook was recorded
    $webhook = PaymentWebhook::where('idempotency_key', 'webhook_key_456')->first();
    expect($webhook)->not->toBeNull();
    expect($webhook->status)->toBe('failure');
});

test('webhook handles idempotency correctly', function () {
    $product = Product::factory()->create(['price' => 10.00]);
    $hold = Hold::createHold($product, 1);
    $order = Order::createFromHold($hold);

    $webhookData = [
        'payment_id' => 'pay_duplicate_123',
        'order_id' => $order->id,
        'status' => 'success',
        'idempotency_key' => 'webhook_key_duplicate',
        'amount' => 10.00,
    ];

    // First webhook call
    $response1 = $this->postJson('/api/payments/webhook', $webhookData);
    $response1->assertStatus(200);

    $order->refresh();
    $originalPaymentId = $order->payment_id;

    // Second webhook call with same idempotency key
    $response2 = $this->postJson('/api/payments/webhook', $webhookData);
    $response2->assertStatus(200)
        ->assertJson([
            'message' => 'Webhook already processed',
            'idempotency_key' => 'webhook_key_duplicate',
            'status' => 'success',
        ]);

    // Order should not be updated again
    $order->refresh();
    expect($order->payment_id)->toBe($originalPaymentId);
});

test('webhook validates request payload', function () {
    $response = $this->postJson('/api/payments/webhook', [
        'invalid' => 'data',
    ]);

    $response->assertStatus(400)
        ->assertJson([
            'error' => 'Invalid webhook payload',
        ]);
});

test('webhook rejects amount mismatch', function () {
    $product = Product::factory()->create(['price' => 25.00]);
    $hold = Hold::createHold($product, 2);
    $order = Order::createFromHold($hold); // Should be 50.00 total

    $response = $this->postJson('/api/payments/webhook', [
        'payment_id' => 'pay_wrong_amount',
        'order_id' => $order->id,
        'status' => 'success',
        'idempotency_key' => 'webhook_wrong_amount',
        'amount' => 30.00, // Wrong amount
    ]);

    $response->assertStatus(200);

    // Order should be cancelled due to amount mismatch
    $order->refresh();
    expect($order->status)->toBe('cancelled');
});

test('webhook handles out-of-order delivery', function () {
    $product = Product::factory()->create(['price' => 20.00]);
    $hold = Hold::createHold($product, 1);

    // Webhook arrives before order is created
    $response = $this->postJson('/api/payments/webhook', [
        'payment_id' => 'pay_out_of_order',
        'order_id' => 999999, // Non-existent order
        'status' => 'success',
        'idempotency_key' => 'webhook_out_of_order',
    ]);

    $response->assertStatus(500);

    // Now create the order
    $order = Order::createFromHold($hold);

    // Webhook should still work when retried with correct order_id
    $response = $this->postJson('/api/payments/webhook', [
        'payment_id' => 'pay_out_of_order',
        'order_id' => $order->id,
        'status' => 'success',
        'idempotency_key' => 'webhook_out_of_order',
    ]);

    $response->assertStatus(200);
    $order->refresh();
    expect($order->status)->toBe('paid');
});

test('webhook works without amount validation', function () {
    $product = Product::factory()->create(['price' => 12.50]);
    $hold = Hold::createHold($product, 2);
    $order = Order::createFromHold($hold);

    $response = $this->postJson('/api/payments/webhook', [
        'payment_id' => 'pay_no_amount',
        'order_id' => $order->id,
        'status' => 'success',
        'idempotency_key' => 'webhook_no_amount',
        // No amount field
    ]);

    $response->assertStatus(200);

    $order->refresh();
    expect($order->status)->toBe('paid');
    expect($order->payment_id)->toBe('pay_no_amount');
});