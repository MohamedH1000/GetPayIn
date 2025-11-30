<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Display the specified product with available stock.
     */
    public function show(Product $product): JsonResponse
    {
        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => number_format($product->price, 2),
            'total_stock' => $product->stock,
            'available_stock' => $product->available_stock,
            'updated_at' => $product->updated_at->toISOString(),
        ]);
    }
}