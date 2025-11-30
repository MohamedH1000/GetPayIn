<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'stock',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
    ];

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get available stock considering active holds
     */
    public function getAvailableStockAttribute(): int
    {
        $cacheKey = "product_{$this->id}_available_stock";

        return Cache::remember($cacheKey, 5, function () {
            return DB::transaction(function () {
                $heldStock = $this->holds()
                    ->where('status', 'active')
                    ->where('expires_at', '>', now())
                    ->sum('quantity');

                return max(0, $this->stock - $heldStock);
            });
        });
    }

    /**
     * Check if product has available stock for requested quantity
     */
    public function hasAvailableStock(int $quantity): bool
    {
        return $this->available_stock >= $quantity;
    }

    /**
     * Reserve stock for a hold (within transaction)
     */
    public function reserveStock(int $quantity): bool
    {
        if (!$this->hasAvailableStock($quantity)) {
            return false;
        }

        // The actual stock reservation is handled by the Hold creation
        // This method validates availability
        return true;
    }

    /**
     * Release stock from a hold (within transaction)
     */
    public function releaseStock(int $quantity): void
    {
        // Stock is released automatically when holds expire or are converted
        // This method can be used for logging or additional business logic
    }

    /**
     * Confirm stock deduction (convert hold to order)
     */
    public function confirmStockDeduction(int $quantity): void
    {
        // Update available stock cache
        Cache::forget("product_{$this->id}_available_stock");
    }
}