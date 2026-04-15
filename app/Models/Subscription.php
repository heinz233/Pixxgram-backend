<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'photographer_id',
        'plan',
        'amount',
        'status',
        'payment_method',
        'transaction_reference',
        'mpesa_receipt',
        'starts_at',
        'ends_at',
        'cancelled_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'starts_at'    => 'datetime',
        'ends_at'      => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                     ->where('ends_at', '>=', now());
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeExpired($query)
    {
        return $query->where('ends_at', '<', now())
                     ->where('status', '!=', 'cancelled');
    }

    public function scopeForMonth($query, $month = null, $year = null)
    {
        return $query->whereMonth('created_at', $month ?? now()->month)
                     ->whereYear('created_at', $year ?? now()->year);
    }

    // -----------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active' && $this->ends_at >= now();
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->ends_at < now();
    }

    public function getDaysRemainingAttribute(): int
    {
        if ($this->is_expired) return 0;
        return (int) now()->diffInDays($this->ends_at);
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    public function photographer()
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }
}