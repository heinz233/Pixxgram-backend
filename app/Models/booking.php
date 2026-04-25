<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Booking extends Model
{
    use HasFactory;

    protected $table = 'bookings';

    // Platform takes 10% of every booking payment
    const COMMISSION_RATE = 0.10;

    protected $fillable = [
        'client_id', 'photographer_id', 'booking_date', 'status', 'notes',
        'amount', 'payment_status', 'mpesa_checkout_request_id',
        'mpesa_receipt', 'paid_at',
        'platform_commission', 'photographer_payout',
        'payout_status', 'payout_reference', 'payout_receipt', 'payout_at',
    ];

    protected $casts = [
        'booking_date'        => 'datetime',
        'paid_at'             => 'datetime',
        'payout_at'           => 'datetime',
        'amount'              => 'decimal:2',
        'platform_commission' => 'decimal:2',
        'photographer_payout' => 'decimal:2',
    ];

    const STATUS_PENDING   = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUSES = ['pending','confirmed','completed','cancelled'];

    const PAYMENT_UNPAID          = 'unpaid';
    const PAYMENT_PENDING_PAYMENT = 'pending_payment';
    const PAYMENT_PAID            = 'paid';

    const PAYOUT_PENDING    = 'pending';
    const PAYOUT_PROCESSING = 'processing';
    const PAYOUT_PAID       = 'paid';
    const PAYOUT_FAILED     = 'failed';

    public function client()      { return $this->belongsTo(User::class, 'client_id'); }
    public function photographer() { return $this->belongsTo(User::class, 'photographer_id'); }

    public function isPaid(): bool      { return $this->payment_status === self::PAYMENT_PAID; }
    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isConfirmed(): bool { return $this->status === self::STATUS_CONFIRMED; }
    public function isCompleted(): bool { return $this->status === self::STATUS_COMPLETED; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }

    public function needsPayment(): bool
    {
        return $this->status === self::STATUS_CONFIRMED
            && $this->payment_status !== self::PAYMENT_PAID;
    }

    /**
     * Calculate and save commission + photographer payout from total amount.
     * Platform: 10%  |  Photographer: 90%
     */
    public function calculateCommission(float $totalAmount): void
    {
        $commission = round($totalAmount * self::COMMISSION_RATE, 2);
        $payout     = round($totalAmount - $commission, 2);
        $this->update([
            'platform_commission' => $commission,
            'photographer_payout' => $payout,
            'payout_status'       => self::PAYOUT_PENDING,
        ]);
    }

    public function scopePendingPayout($query)
    {
        return $query->where('payment_status', self::PAYMENT_PAID)
                     ->where('payout_status', self::PAYOUT_PENDING);
    }
}
