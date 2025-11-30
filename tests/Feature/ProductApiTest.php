<?php

use App\Models\Product;

test('can get product details with available stock', function () {
    $product = Product::factory()->create([
        'name' => 'Test Widget',
        'price' => 29.99,
        'stock' => 100,
    ]);

    $response = $this->getJson("/api/products/{$product->id}");

    $response->assertStatus(200)
        ->assertJson([
            'id' => $product->id,
            'name' => 'Test Widget',
            'price' => '29.99',
            'total_stock' => 100,
            'available_stock' => 100,
        ]);
});

test('returns 404 for non-existent product', function () {
    $response = $this->getJson('/api/products/999');

    $response->assertStatus(404);
});

test('available stock decreases when holds are created', function () {
    $product = Product::factory()->create(['stock' => 100]);

    // Create some holds
    $hold1 = \App\Models\Hold::createHold($product, 20);
    $hold2 = \App\Models\Hold::createHold($product, 30);

    expect($hold1)->not->toBeNull();
    expect($hold2)->not->toBeNull();

    $response = $this->getJson("/api/products/{$product->id}");

    $response->assertStatus(200)
        ->assertJson([
            'total_stock' => 100,
            'available_stock' => 50, // 100 - 20 - 30
        ]);
});

test('available stock returns to zero when no stock available', function () {
    $product = Product::factory()->create(['stock' => 10]);

    // Create hold that uses all stock
    $hold = \App\Models\Hold::createHold($product, 10);

    expect($hold)->not->toBeNull();

    $response = $this->getJson("/api/products/{$product->id}");

    $response->assertStatus(200)
        ->assertJson([
            'total_stock' => 10,
            'available_stock' => 0,
        ]);
});