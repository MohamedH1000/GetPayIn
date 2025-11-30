<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Hold extends Model
{
    use HasFactory;

    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CONVERTED = 'converted';

    const HOLD_DURATION_MINUTES = 2;

    protected $fillable = [
        'product_id',
        'quantity',
        'expires_at',
        'status',
        'hold_token',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'quantity' => 'integer',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($hold) {
            $hold->hold_token = Str::random(32);
            $hold->expires_at = now()->addMinutes(self::HOLD_DURATION_MINUTES);
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->hasOne(Order::class);
    }

    /**
     * Check if hold is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if hold is active and not expired
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && !$this->isExpired();
    }

    /**
     * Mark hold as expired
     */
    public function markAsExpired(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $this->status = self::STATUS_EXPIRED;
        return $this->save();
    }

    /**
     * Mark hold as converted to order
     */
    public function markAsConverted(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $this->status = self::STATUS_CONVERTED;
        return $this->save();
    }

    /**
     * Create a hold for a product with concurrency control
     */
    public static function createHold(Product $product, int $quantity): ?self
    {
        return \DB::transaction(function () use ($product, $quantity) {
            // Lock the product row to prevent race conditions
            $lockedProduct = Product::lockForUpdate()->find($product->id);

            if (!$lockedProduct->hasAvailableStock($quantity)) {
                return null;
            }

            // Create the hold
            $hold = new static([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'expires_at' => now()->addMinutes(self::HOLD_DURATION_MINUTES),
                'status' => self::STATUS_ACTIVE,
                'hold_token' => Str::random(32),
            ]);

            $hold->save();

            // Clear cache to refresh available stock
            \Cache::forget("product_{$product->id}_available_stock");

            return $hold;
        });
    }

    /**
     * Find active hold by token
     */
    public static function findActiveByToken(string $token): ?self
    {
        return static::where('hold_token', $token)
            ->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Get expires_at formatted for API response
     */
    public function getExpiresAtForApi(): string
    {
        return $this->expires_at->toISOString();
    }
}