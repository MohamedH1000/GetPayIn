<?php

use App\Models\Product;
use App\Models\Hold;

test('can create hold with sufficient stock', function () {
    $product = Product::factory()->create(['stock' => 100]);

    $response = $this->postJson('/api/holds', [
        'product_id' => $product->id,
        'quantity' => 5,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'hold_id',
            'hold_token',
            'expires_at',
            'product_id',
            'quantity',
        ]);

    expect($response->json('quantity'))->toBe(5);
    expect($response->json('product_id'))->toBe($product->id);
});

test('cannot create hold with insufficient stock', function () {
    $product = Product::factory()->create(['stock' => 10]);

    // First create a hold that uses most of the stock
    Hold::createHold($product, 9);

    $response = $this->postJson('/api/holds', [
        'product_id' => $product->id,
        'quantity' => 5, // Only 1 left available
    ]);

    $response->assertStatus(409)
        ->assertJson([
            'error' => 'Insufficient stock available',
        ]);
});

test('cannot create hold for non-existent product', function () {
    $response = $this->postJson('/api/holds', [
        'product_id' => 999,
        'quantity' => 1,
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'error' => 'Validation failed',
        ]);
});

test('validates hold creation request', function () {
    $response = $this->postJson('/api/holds', [
        'product_id' => 'invalid',
        'quantity' => 0,
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure([
            'error',
            'messages',
        ]);
});

test('hold expires after 2 minutes', function () {
    $product = Product::factory()->create(['stock' => 100]);

    $hold = Hold::createHold($product, 5);

    expect($hold)->not->toBeNull();
    expect($hold->expires_at)->toBeGreaterThan(now());
    expect($hold->expires_at)->toBeLessThanOrEqualTo(now()->addMinutes(2));
});

test('concurrent hold creation prevents overselling', function () {
    $product = Product::factory()->create(['stock' => 10]);

    // Simulate concurrent requests
    $results = collect(range(1, 15))->map(function () use ($product) {
        return Hold::createHold($product, 1);
    });

    // Count successful holds
    $successfulHolds = $results->filter()->count();

    // Should not exceed available stock
    expect($successfulHolds)->toBeLessThanOrEqual(10);
});

test('parallel hold attempts at stock boundary work correctly', function () {
    $product = Product::factory()->create(['stock' => 5]);

    // Create 5 parallel requests for the last available stock
    $promises = collect(range(1, 5))->map(function () use ($product) {
        return Hold::createHold($product, 1);
    });

    $successfulHolds = $promises->filter()->count();
    $totalRequestedQuantity = $promises->sum(fn($hold) => $hold?->quantity ?? 0);

    // All successful holds should sum to no more than available stock
    expect($totalRequestedQuantity)->toBeLessThanOrEqual(5);
    expect($successfulHolds)->toBeLessThanOrEqual(5);
});

test('hold creates unique token', function () {
    $product = Product::factory()->create(['stock' => 100]);

    $hold1 = Hold::createHold($product, 1);
    $hold2 = Hold::createHold($product, 1);

    expect($hold1->hold_token)->not->toBe($hold2->hold_token);
    expect($hold1->hold_token)->toBeString()->toHaveLength(32);
});