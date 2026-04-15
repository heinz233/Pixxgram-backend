<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Portfolio extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'photographer_id',
        'title',
        'description',
        'image_url',
        'thumbnail_url',
        'category',
        'tags',
        'views',
        'saves',
        'inquiries',
    ];

    protected $casts = [
        'tags'      => 'array',
        'views'     => 'integer',
        'saves'     => 'integer',
        'inquiries' => 'integer',
    ];

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

   public function getPortfolio()
{
    $user = $this->requirePhotographer();
 
    $portfolios = \App\Models\Portfolio::where('photographer_id', $user->id)
        ->latest()
        ->get();
 
    return response()->json([
        'portfolios' => $portfolios,
    ]);
}
 
// DELETE /api/photographer/portfolio/{id}
public function deletePortfolioItem($id)
{
    $user = $this->requirePhotographer();
 
    $item = \App\Models\Portfolio::where('id', $id)
        ->where('photographer_id', $user->id)  // ensure ownership
        ->firstOrFail();
 
    // Delete the actual files from storage
    if ($item->image_url) {
        $path = str_replace('/storage/', '', parse_url($item->image_url, PHP_URL_PATH));
        \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
    }
    if ($item->thumbnail_url) {
        $path = str_replace('/storage/', '', parse_url($item->thumbnail_url, PHP_URL_PATH));
        \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
    }
 
    $item->delete();
 
    return response()->json(['message' => 'Portfolio item deleted successfully.']);
}
}
