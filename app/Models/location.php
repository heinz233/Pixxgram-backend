<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'region',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /** Only return locations that are marked active */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Filter by region */
    public function scopeInRegion($query, $region)
    {
        return $query->where('region', $region);
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    /** Photographers who have listed this location */
    public function photographers()
    {
        return $this->hasMany(User::class, 'location_id')
                    ->where('role', 'photographer');
    }

    /** Photographer profiles linked to this location */
    public function photographerProfiles()
    {
        return $this->hasMany(PhotographerProfile::class, 'location_id');
    }
}