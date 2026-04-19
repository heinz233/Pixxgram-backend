<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Booking extends Model
{
    use HasFactory;

    protected $table = 'bookings';

    protected $fillable = [
        'client_id',
        'photographer_id',
        'booking_date',
        'status',
        'notes',
        // Payment fields
        'amount',
        'payment_status',
        'mpesa_checkout_request_id',
        'mpesa_receipt',
        'paid_at',
    ];

    protected $casts = [
        'booking_date' => 'datetime',
        'paid_at'      => 'datetime',
        'amount'       => 'decimal:2',
    ];

    // ── Status constants ───────────────────────────────────────────
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

    // ── Payment status constants ───────────────────────────────────
    const PAYMENT_UNPAID          = 'unpaid';
    const PAYMENT_PENDING_PAYMENT = 'pending_payment';
    const PAYMENT_PAID            = 'paid';

    // ── Relationships ──────────────────────────────────────────────
    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function photographer()
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────
    public function scopePending($query)   { return $query->where('status', self::STATUS_PENDING); }
    public function scopeConfirmed($query) { return $query->where('status', self::STATUS_CONFIRMED); }
    public function scopeCompleted($query) { return $query->where('status', self::STATUS_COMPLETED); }
    public function scopeCancelled($query) { return $query->where('status', self::STATUS_CANCELLED); }
    public function scopePaid($query)      { return $query->where('payment_status', self::PAYMENT_PAID); }
    public function scopeUnpaid($query)    { return $query->where('payment_status', self::PAYMENT_UNPAID); }

    // ── Helpers ────────────────────────────────────────────────────
    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isConfirmed(): bool { return $this->status === self::STATUS_CONFIRMED; }
    public function isCompleted(): bool { return $this->status === self::STATUS_COMPLETED; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }
    public function isPaid(): bool      { return $this->payment_status === self::PAYMENT_PAID; }
    public function needsPayment(): bool
    {
        return $this->status === self::STATUS_CONFIRMED
            && $this->payment_status !== self::PAYMENT_PAID;
    }
}
