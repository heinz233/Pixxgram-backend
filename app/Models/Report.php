<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Report extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'photographer_id',
        'reason',
        'description',
        'status',  // pending | resolved | dismissed
    ];

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function photographer()
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }
}
