<?php

namespace App\Jobs;

use App\Models\Hold;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireHolds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting hold expiry job');

        $expiredCount = DB::transaction(function () {
            // Find expired active holds
            $expiredHolds = Hold::where('status', Hold::STATUS_ACTIVE)
                ->where('expires_at', '<', now())
                ->lockForUpdate()
                ->get();

            $expiredCount = $expiredHolds->count();

            foreach ($expiredHolds as $hold) {
                try {
                    // Mark the hold as expired
                    $hold->markAsExpired();

                    // Check if there's a pending order for this hold
                    $pendingOrder = Order::where('hold_id', $hold->id)
                        ->where('status', Order::STATUS_PENDING)
                        ->first();

                    if ($pendingOrder) {
                        // Mark the order as expired
                        $pendingOrder->status = Order::STATUS_EXPIRED;
                        $pendingOrder->save();

                        Log::info('Order expired due to hold expiry', [
                            'order_id' => $pendingOrder->id,
                            'hold_id' => $hold->id,
                            'product_id' => $hold->product_id,
                        ]);
                    }

                    // Clear product cache
                    \Cache::forget("product_{$hold->product_id}_available_stock");

                    Log::info('Hold expired', [
                        'hold_id' => $hold->id,
                        'product_id' => $hold->product_id,
                        'quantity' => $hold->quantity,
                    ]);

                } catch (\Exception $e) {
                    Log::error('Failed to expire hold', [
                        'hold_id' => $hold->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $expiredCount;
        });

        Log::info('Hold expiry job completed', [
            'expired_holds_count' => $expiredCount,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Hold expiry job failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}