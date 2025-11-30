<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hold;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class HoldController extends Controller
{
    /**
     * Store a newly created hold.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        try {
            $hold = DB::transaction(function () use ($request) {
                $product = Product::lockForUpdate()->find($request->product_id);

                if (!$product) {
                    throw new \Exception('Product not found');
                }

                // Create hold with concurrency control
                $hold = Hold::createHold($product, $request->quantity);

                if (!$hold) {
                    return null;
                }

                return $hold;
            });

            if (!$hold) {
                return response()->json([
                    'error' => 'Insufficient stock available',
                    'message' => 'Not enough items are available to create this hold',
                ], 409);
            }

            // Log successful hold creation for monitoring
            Log::info('Hold created successfully', [
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
                'quantity' => $hold->quantity,
                'hold_token' => $hold->hold_token,
                'expires_at' => $hold->expires_at,
            ]);

            return response()->json([
                'hold_id' => $hold->id,
                'hold_token' => $hold->hold_token,
                'expires_at' => $hold->getExpiresAtForApi(),
                'product_id' => $hold->product_id,
                'quantity' => $hold->quantity,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create hold', [
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => 'Failed to create hold due to a technical issue',
            ], 500);
        }
    }
}