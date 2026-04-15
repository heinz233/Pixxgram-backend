<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    // Fix: original method was missing `return` — relationship was never registered.
    public function users()
    {
        return $this->hasMany(User::class);
    }
}
