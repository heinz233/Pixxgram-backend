<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhotographerProfile extends Model
{
    protected $fillable = [
        'user_id', 'age', 'gender', 'location', 'bio', 'profile_photo',
        'hourly_rate', 'service_rates', 'subscription_status',
        'subscription_end_date', 'average_rating', 'total_ratings', 'badges'
    ];

    protected $casts = [
        'service_rates' => 'array',
        'badges' => 'array',
        'subscription_end_date' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isSubscriptionActive()
    {
        return $this->subscription_status === 'active' && 
               ($this->subscription_end_date && $this->subscription_end_date->isFuture());
    }
}
