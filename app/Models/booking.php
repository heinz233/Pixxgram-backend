<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Booking extends Model
{
    use HasFactory;

    // ─────────────────────────────────────────────────────────────────
    // Table & fillable
    // ─────────────────────────────────────────────────────────────────

    protected $table = 'bookings';

    protected $fillable = [
        'client_id',
        'photographer_id',
        'booking_date',
        'status',
        'notes',
    ];

    protected $casts = [
        'booking_date' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────────
    // Status constants — prevents typos across the codebase
    // ─────────────────────────────────────────────────────────────────

    const STATUS_PENDING   = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    // ─────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────

    /**
     * The client who made the booking.
     */
    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * The photographer being booked.
     */
    public function photographer()
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // Query scopes
    // ─────────────────────────────────────────────────────────────────

    /** Bookings with a specific status */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /** Only pending bookings */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** Only confirmed bookings */
    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    /** Only completed bookings */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /** Only cancelled bookings */
    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /** Upcoming bookings (booking_date in the future) */
    public function scopeUpcoming($query)
    {
        return $query->where('booking_date', '>', now());
    }

    /** Past bookings */
    public function scopePast($query)
    {
        return $query->where('booking_date', '<=', now());
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers / Accessors
    // ─────────────────────────────────────────────────────────────────

    /** Whether this booking is still pending */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Whether this booking has been confirmed */
    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /** Whether this booking has been completed */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /** Whether this booking has been cancelled */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** Whether the booking date is in the future */
    public function isUpcoming(): bool
    {
        return $this->booking_date?->isFuture() ?? false;
    }
}