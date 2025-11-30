<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentWebhookController extends Controller
{
    /**
     * Handle payment webhook with idempotency
     */
    public function handle(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|string|max:255',
            'order_id' => 'required|integer|exists:orders,id',
            'status' => 'required|in:success,failure',
            'idempotency_key' => 'required|string|max:255',
            'amount' => 'sometimes|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid webhook payload',
                'messages' => $validator->errors(),
            ], 400);
        }

        $idempotencyKey = $request->input('idempotency_key');
        $paymentId = $request->input('payment_id');
        $orderId = $request->input('order_id');
        $status = $request->input('status');

        try {
            // Check if this webhook has already been processed
            $processedWebhook = PaymentWebhook::where('idempotency_key', $idempotencyKey)
                ->where('payment_id', $paymentId)
                ->first();

            if ($processedWebhook) {
                Log::info('Duplicate webhook received', [
                    'idempotency_key' => $idempotencyKey,
                    'payment_id' => $paymentId,
                    'original_status' => $processedWebhook->status,
                ]);

                // Return the original response for idempotency
                return response()->json([
                    'message' => 'Webhook already processed',
                    'idempotency_key' => $idempotencyKey,
                    'status' => $processedWebhook->status,
                ]);
            }

            // Record the webhook first to ensure idempotency
            $webhookRecord = DB::transaction(function () use ($request, $idempotencyKey, $paymentId, $orderId, $status) {
                // Find and lock the order
                $order = Order::lockForUpdate()->find($orderId);

                if (!$order) {
                    throw new \Exception('Order not found');
                }

                // Record the webhook
                $webhook = PaymentWebhook::create([
                    'idempotency_key' => $idempotencyKey,
                    'payment_id' => $paymentId,
                    'status' => $status,
                    'payload' => $request->all(),
                    'processed_at' => now(),
                ]);

                // Process the payment status
                if ($status === 'success') {
                    // Validate amount if provided
                    if ($request->has('amount')) {
                        $expectedAmount = $order->total_amount;
                        $receivedAmount = (float) $request->input('amount');

                        if (abs($expectedAmount - $receivedAmount) > 0.01) {
                            // Amount mismatch - treat as failure
                            $order->cancel();
                            Log::warning('Payment amount mismatch', [
                                'order_id' => $order->id,
                                'expected' => $expectedAmount,
                                'received' => $receivedAmount,
                            ]);
                        } else {
                            // Amount matches - mark as paid
                            $order->markAsPaid($paymentId, $idempotencyKey);
                            Log::info('Order marked as paid', [
                                'order_id' => $order->id,
                                'payment_id' => $paymentId,
                                'amount' => $receivedAmount,
                            ]);
                        }
                    } else {
                        // No amount provided - mark as paid
                        $order->markAsPaid($paymentId, $idempotencyKey);
                        Log::info('Order marked as paid (no amount validation)', [
                            'order_id' => $order->id,
                            'payment_id' => $paymentId,
                        ]);
                    }
                } else {
                    // Payment failed - cancel the order
                    $order->cancel();
                    Log::info('Order cancelled due to payment failure', [
                        'order_id' => $order->id,
                        'payment_id' => $paymentId,
                    ]);
                }

                return $webhook;
            });

            // Final state check and logging
            $finalOrder = Order::find($orderId);
            Log::info('Webhook processed successfully', [
                'idempotency_key' => $idempotencyKey,
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'order_status' => $finalOrder->status,
            ]);

            return response()->json([
                'message' => 'Webhook processed successfully',
                'idempotency_key' => $idempotencyKey,
                'payment_id' => $paymentId,
                'order_status' => $finalOrder->status,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process payment webhook', [
                'idempotency_key' => $idempotencyKey,
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => 'Failed to process webhook due to a technical issue',
            ], 500);
        }
    }
}