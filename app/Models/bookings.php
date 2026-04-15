<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = [
        'client_id', 'photographer_id', 'booking_date', 'status', 'notes'
    ];

    protected $casts = [
        'booking_date' => 'datetime'
    ];

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function photographer()
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }
}