<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hold;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    /**
     * Store a newly created order from a hold.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'hold_id' => 'required|exists:holds,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        try {
            $order = DB::transaction(function () use ($request) {
                // Find and lock the hold
                $hold = Hold::lockForUpdate()->find($request->hold_id);

                if (!$hold) {
                    throw new \Exception('Hold not found');
                }

                // Check if hold is still active and not expired
                if (!$hold->isActive()) {
                    return null;
                }

                // Check if hold has already been used for an order
                $existingOrder = Order::where('hold_id', $hold->id)->first();
                if ($existingOrder) {
                    // Return the existing order instead of creating a duplicate
                    return $existingOrder;
                }

                // Create order from hold
                $order = Order::createFromHold($hold);

                if (!$order) {
                    return null;
                }

                return $order;
            });

            if (!$order) {
                return response()->json([
                    'error' => 'Hold expired or invalid',
                    'message' => 'The specified hold is no longer valid or has expired',
                ], 410);
            }

            // Log successful order creation
            Log::info('Order created successfully', [
                'order_id' => $order->id,
                'hold_id' => $order->hold_id,
                'product_id' => $order->product_id,
                'quantity' => $order->quantity,
                'total_amount' => $order->total_amount,
                'status' => $order->status,
            ]);

            return response()->json([
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'quantity' => $order->quantity,
                'total_amount' => number_format($order->total_amount, 2),
                'status' => $order->status,
                'created_at' => $order->created_at->toISOString(),
                'payment_id' => $order->payment_id,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create order', [
                'hold_id' => $request->hold_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => 'Failed to create order due to a technical issue',
            ], 500);
        }
    }
}