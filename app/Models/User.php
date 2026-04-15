<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'user_image', 'is_active',
        'role_id', 'phoneNumber', 'gymLocation', 'gender', 'dob', 'status',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    // Relationships
    public function role()                { return $this->belongsTo(Role::class); }
    public function photographerProfile() { return $this->hasOne(PhotographerProfile::class, 'user_id'); }
    public function portfolios()          { return $this->hasMany(Portfolio::class, 'photographer_id'); }
    public function bookingsAsClient()    { return $this->hasMany(Booking::class, 'client_id'); }
    public function bookingsAsPhotographer() { return $this->hasMany(Booking::class, 'photographer_id'); }
    public function ratingsReceived()     { return $this->hasMany(Rating::class, 'photographer_id'); }
    public function ratingsGiven()        { return $this->hasMany(Rating::class, 'client_id'); }
    public function subscriptions()       { return $this->hasMany(Subscription::class, 'photographer_id'); }
    public function reports()             { return $this->hasMany(Report::class, 'photographer_id'); }

    // Role helpers
    public function isAdmin(): bool       { return $this->role_id === 1; }
    public function isPhotographer(): bool{ return $this->role_id === 2; }
    public function isClient(): bool      { return $this->role_id === 3; }
}
