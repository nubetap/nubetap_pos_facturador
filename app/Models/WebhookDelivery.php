<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'webhook_id',
        'event',
        'payload',
        'status',
        'attempts',
        'response_code',
        'response_body',
        'error_message',
        'delivered_at',
        'next_retry_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'delivered_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    /**
     * Get the webhook that owns the delivery
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    /**
     * Check if delivery is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if delivery was successful
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Check if delivery failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if should retry
     */
    public function shouldRetry(): bool
    {
        return $this->isPending()
            && $this->attempts < $this->webhook->max_retries
            && ($this->next_retry_at === null || $this->next_retry_at->isPast());
    }

    /**
     * Mark as success
     */
    public function markAsSuccess(int $responseCode, ?string $responseBody = null): void
    {
        $this->update([
            'status' => 'success',
            'response_code' => $responseCode,
            'response_body' => $responseBody,
            'delivered_at' => now(),
            'next_retry_at' => null,
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $error, ?int $responseCode = null): void
    {
        $attempts = $this->attempts + 1;
        $webhook = $this->webhook;

        $status = $attempts >= $webhook->max_retries ? 'failed' : 'pending';
        $nextRetry = $attempts < $webhook->max_retries
            ? now()->addSeconds($webhook->retry_delay * $attempts)
            : null;

        $this->update([
            'status' => $status,
            'attempts' => $attempts,
            'error_message' => $error,
            'response_code' => $responseCode,
            'next_retry_at' => $nextRetry,
        ]);
    }
}
