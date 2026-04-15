<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Rating extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'photographer_id',
        'stars',
        'comment',
    ];

    protected $casts = [
        'stars' => 'integer',
    ];

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
