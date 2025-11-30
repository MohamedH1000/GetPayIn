<?php

use App\Models\Product;
use App\Models\Hold;
use App\Models\Order;
use App\Jobs\ExpireHolds;

test('hold expiry job expires old holds correctly', function () {
    $product = Product::factory()->create(['stock' => 100]);

    // Create a hold and manually expire it
    $hold = Hold::createHold($product, 5);
    $hold->expires_at = now()->subMinutes(5);
    $hold->save();

    // Create an order from this hold
    $order = Order::createFromHold($hold);
    expect($order->status)->toBe('pending');

    // Run the expiry job
    (new ExpireHolds)->handle();

    // Hold should be expired
    $hold->refresh();
    expect($hold->status)->toBe('expired');

    // Order should be expired
    $order->refresh();
    expect($order->status)->toBe('expired');
});

test('hold expiry job does not affect active holds', function () {
    $product = Product::factory()->create(['stock' => 100]);

    // Create an active hold
    $activeHold = Hold::createHold($product, 3);
    expect($activeHold->isActive())->toBeTrue();

    // Create an order from this hold
    $order = Order::createFromHold($activeHold);
    expect($order->status)->toBe('pending');

    // Run the expiry job
    (new ExpireHolds)->handle();

    // Active hold should remain unchanged
    $activeHold->refresh();
    expect($activeHold->isActive())->toBeTrue();

    // Order should remain pending
    $order->refresh();
    expect($order->status)->toBe('pending');
});

test('hold expiry releases stock availability', function () {
    $product = Product::factory()->create(['stock' => 100]);

    // Create a hold that uses some stock
    $hold = Hold::createHold($product, 30);
    expect($product->available_stock)->toBe(70);

    // Expire the hold
    $hold->expires_at = now()->subMinutes(1);
    $hold->save();

    // Run expiry job
    (new ExpireHolds)->handle();

    // Stock should be available again
    expect($product->available_stock)->toBe(100);
});

test('hold expiry command works correctly', function () {
    $product = Product::factory()->create(['stock' => 50]);

    // Create and expire a hold
    $hold = Hold::createHold($product, 10);
    $hold->expires_at = now()->subMinutes(3);
    $hold->save();

    expect($hold->status)->toBe('active');

    // Run the command
    $this->artisan('holds:expire')
        ->assertExitCode(0);

    // Hold should be expired
    $hold->refresh();
    expect($hold->status)->toBe('expired');
});

test('multiple holds can be expired in single job', function () {
    $product = Product::factory()->create(['stock' => 200]);

    // Create multiple holds
    $holds = collect();
    for ($i = 0; $i < 5; $i++) {
        $hold = Hold::createHold($product, 10);
        $hold->expires_at = now()->subMinutes($i + 1);
        $hold->save();
        $holds->push($hold);
    }

    // Run expiry job
    (new ExpireHolds)->handle();

    // All holds should be expired
    $holds->each(function ($hold) {
        $hold->refresh();
        expect($hold->status)->toBe('expired');
    });
});

test('hold expiry does not affect converted holds', function () {
    $product = Product::factory()->create(['stock' => 100]);

    // Create a hold and convert it to order
    $hold = Hold::createHold($product, 5);
    $order = Order::createFromHold($hold);
    $order->markAsPaid('payment_123');

    // Manually expire the hold timestamp but keep it as converted
    $hold->expires_at = now()->subMinutes(5);
    $hold->save();

    expect($hold->status)->toBe('converted');

    // Run expiry job
    (new ExpireHolds)->handle();

    // Converted hold should remain unchanged
    $hold->refresh();
    expect($hold->status)->toBe('converted');
});