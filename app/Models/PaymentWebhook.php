<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentWebhook extends Model
{
    use HasFactory;

    protected $fillable = [
        'idempotency_key',
        'payment_id',
        'status',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * Find webhook by idempotency key and payment ID
     */
    public static function findByIdempotencyAndPayment(string $idempotencyKey, string $paymentId): ?self
    {
        return static::where('idempotency_key', $idempotencyKey)
            ->where('payment_id', $paymentId)
            ->first();
    }
}