<?php

use App\Models\Product;
use App\Models\Hold;
use App\Models\Order;

test('can create order from valid hold', function () {
    $product = Product::factory()->create([
        'price' => 29.99,
        'stock' => 100,
    ]);

    $hold = Hold::createHold($product, 2);

    $response = $this->postJson('/api/orders', [
        'hold_id' => $hold->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'order_id',
            'product_id',
            'quantity',
            'total_amount',
            'status',
            'created_at',
            'payment_id',
        ]);

    expect($response->json('product_id'))->toBe($product->id);
    expect($response->json('quantity'))->toBe(2);
    expect($response->json('total_amount'))->toBe('59.98'); // 29.99 * 2
    expect($response->json('status'))->toBe('pending');
});

test('cannot create order from expired hold', function () {
    $product = Product::factory()->create(['stock' => 100]);

    // Create and manually expire a hold
    $hold = Hold::createHold($product, 2);
    $hold->expires_at = now()->subMinutes(1);
    $hold->save();

    $response = $this->postJson('/api/orders', [
        'hold_id' => $hold->id,
    ]);

    $response->assertStatus(410)
        ->assertJson([
            'error' => 'Hold expired or invalid',
        ]);
});

test('cannot create order from non-existent hold', function () {
    $response = $this->postJson('/api/orders', [
        'hold_id' => 999,
    ]);

    $response->assertStatus(422);
});

test('cannot create duplicate orders from same hold', function () {
    $product = Product::factory()->create(['price' => 29.99]);

    $hold = Hold::createHold($product, 1);

    // Create first order
    $response1 = $this->postJson('/api/orders', ['hold_id' => $hold->id]);
    $response1->assertStatus(201);

    // Try to create second order with same hold
    $response2 = $this->postJson('/api/orders', ['hold_id' => $hold->id]);
    $response2->assertStatus(201);

    // Should return the same order
    expect($response1->json('order_id'))->toBe($response2->json('order_id'));
});

test('order creation validates request', function () {
    $response = $this->postJson('/api/orders', [
        'hold_id' => 'invalid',
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure([
            'error',
            'messages',
        ]);
});

test('order holds correct product information', function () {
    $product = Product::factory()->create([
        'name' => 'Test Product',
        'price' => 19.99,
        'stock' => 50,
    ]);

    $hold = Hold::createHold($product, 3);
    $order = Order::createFromHold($hold);

    expect($order->product_id)->toBe($product->id);
    expect($order->quantity)->toBe(3);
    expect($order->total_amount)->toBe(59.97); // 19.99 * 3
    expect($order->status)->toBe('pending');
});

test('order can be marked as paid', function () {
    $product = Product::factory()->create(['price' => 10.00]);
    $hold = Hold::createHold($product, 2);
    $order = Order::createFromHold($hold);

    $result = $order->markAsPaid('payment_123', 'idempotent_key_456');

    expect($result)->toBeTrue();
    $order->refresh();

    expect($order->status)->toBe('paid');
    expect($order->payment_id)->toBe('payment_123');
    expect($order->idempotency_key)->toBe('idempotent_key_456');
});

test('order can be cancelled', function () {
    $product = Product::factory()->create(['price' => 25.00]);
    $hold = Hold::createHold($product, 1);
    $order = Order::createFromHold($hold);

    $result = $order->cancel();

    expect($result)->toBeTrue();
    $order->refresh();

    expect($order->status)->toBe('cancelled');
});

test('cannot pay already paid order', function () {
    $product = Product::factory()->create(['price' => 15.00]);
    $hold = Hold::createHold($product, 1);
    $order = Order::createFromHold($hold);
    $order->markAsPaid('payment_123');

    $result = $order->markAsPaid('payment_456');

    expect($result)->toBeFalse();
    $order->refresh();

    expect($order->payment_id)->toBe('payment_123'); // Should not change
});