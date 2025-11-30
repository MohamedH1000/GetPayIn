<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'product_id',
        'hold_id',
        'quantity',
        'total_amount',
        'status',
        'payment_id',
        'idempotency_key',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'quantity' => 'integer',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(Hold::class);
    }

    /**
     * Check if order can be paid
     */
    public function canBePaid(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if order is paid
     */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * Mark order as paid
     */
    public function markAsPaid(string $paymentId, ?string $idempotencyKey = null): bool
    {
        if (!$this->canBePaid()) {
            return false;
        }

        return \DB::transaction(function () use ($paymentId, $idempotencyKey) {
            $this->status = self::STATUS_PAID;
            $this->payment_id = $paymentId;

            if ($idempotencyKey) {
                $this->idempotency_key = $idempotencyKey;
            }

            if (!$this->save()) {
                return false;
            }

            // Convert the associated hold to converted status
            if ($this->hold && $this->hold->status === Hold::STATUS_ACTIVE) {
                $this->hold->markAsConverted();
            }

            // Update product stock cache
            if ($this->product) {
                $this->product->confirmStockDeduction($this->quantity);
            }

            return true;
        });
    }

    /**
     * Cancel the order and release hold
     */
    public function cancel(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        return \DB::transaction(function () {
            $this->status = self::STATUS_CANCELLED;

            if (!$this->save()) {
                return false;
            }

            // Release the hold
            if ($this->hold && $this->hold->status === Hold::STATUS_ACTIVE) {
                $this->hold->markAsExpired();
            }

            // Clear product stock cache
            if ($this->product) {
                \Cache::forget("product_{$this->product->id}_available_stock");
            }

            return true;
        });
    }

    /**
     * Create order from hold
     */
    public static function createFromHold(Hold $hold): ?self
    {
        if (!$hold->isActive()) {
            return null;
        }

        return \DB::transaction(function () use ($hold) {
            $product = $hold->product;
            $totalAmount = $product->price * $hold->quantity;

            $order = new static([
                'product_id' => $product->id,
                'hold_id' => $hold->id,
                'quantity' => $hold->quantity,
                'total_amount' => $totalAmount,
                'status' => self::STATUS_PENDING,
            ]);

            $order->save();

            return $order;
        });
    }

    /**
     * Find order by idempotency key
     */
    public static function findByIdempotencyKey(string $key): ?self
    {
        return static::where('idempotency_key', $key)->first();
    }

    /**
     * Check if order has expired (hold expired before payment)
     */
    public function hasExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED ||
               ($this->hold && $this->hold->isExpired() && $this->status === self::STATUS_PENDING);
    }

    /**
     * Mark order as expired if hold has expired
     */
    public function markAsExpiredIfHoldExpired(): bool
    {
        if ($this->status !== self::STATUS_PENDING || !$this->hold) {
            return false;
        }

        if ($this->hold->isExpired()) {
            $this->status = self::STATUS_EXPIRED;
            return $this->save();
        }

        return false;
    }
}