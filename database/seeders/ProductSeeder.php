<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        Product::create([
            'name' => 'Flash Sale Widget',
            'description' => 'A limited edition widget available during our flash sale event. High demand, limited supply!',
            'price' => 29.99,
            'stock' => 100, // Limited stock for flash sale
        ]);

        Product::create([
            'name' => 'Premium Gizmo',
            'description' => 'Premium quality gizmo with advanced features. Exclusive flash sale pricing.',
            'price' => 149.99,
            'stock' => 50, // Even more limited
        ]);

        Product::create([
            'name' => 'Special Edition Thingamajig',
            'description' => 'Limited edition collectible thingamajig. Once they\'re gone, they\'re gone!',
            'price' => 89.99,
            'stock' => 25, // Very limited stock
        ]);
    }
}