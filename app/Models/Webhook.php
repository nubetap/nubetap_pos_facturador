<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webhook extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'url',
        'method',
        'events',
        'headers',
        'secret',
        'active',
        'timeout',
        'max_retries',
        'retry_delay',
        'last_triggered_at',
        'last_status',
        'last_error',
        'success_count',
        'failure_count',
    ];

    protected $casts = [
        'events' => 'array',
        'headers' => 'array',
        'active' => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    /**
     * Get the company that owns the webhook
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the webhook deliveries
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * Check if webhook is active
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Check if webhook handles the given event
     */
    public function handlesEvent(string $event): bool
    {
        return in_array($event, $this->events);
    }

    /**
     * Record successful delivery
     */
    public function recordSuccess(): void
    {
        $this->update([
            'last_triggered_at' => now(),
            'last_status' => 'success',
            'last_error' => null,
            'success_count' => $this->success_count + 1,
        ]);
    }

    /**
     * Record failed delivery
     */
    public function recordFailure(string $error): void
    {
        $this->update([
            'last_triggered_at' => now(),
            'last_status' => 'failed',
            'last_error' => $error,
            'failure_count' => $this->failure_count + 1,
        ]);
    }

    /**
     * Get failure rate
     */
    public function getFailureRate(): float
    {
        $total = $this->success_count + $this->failure_count;

        if ($total === 0) {
            return 0;
        }

        return ($this->failure_count / $total) * 100;
    }
}
