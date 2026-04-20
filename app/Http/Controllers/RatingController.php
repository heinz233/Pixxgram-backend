<?php

namespace App\Http\Controllers;

use App\Models\Rating;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RatingController extends Controller
{
    public function store(Request $request, $photographerId)
    {
        $request->validate([
            'stars'   => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        // Ensure photographer exists
        $photographer = User::where('id', $photographerId)
            ->where('role_id', 2)
            ->firstOrFail();

        $rating = Rating::updateOrCreate(
            [
                'client_id'       => Auth::id(),
                'photographer_id' => $photographerId,
            ],
            [
                'stars'   => $request->stars,
                'comment' => $request->comment,
            ]
        );

        $this->updatePhotographerRating($photographerId);

        return response()->json([
            'message' => 'Rating submitted successfully.',
            'rating'  => $rating,
        ], 201);
    }

    public function index($photographerId)
    {
        $ratings = Rating::where('photographer_id', $photographerId)
            ->with('client:id,name,user_image')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($ratings);
    }

    private function updatePhotographerRating($photographerId): void
    {
        $average = Rating::where('photographer_id', $photographerId)->avg('stars');
        $total   = Rating::where('photographer_id', $photographerId)->count();

        $photographer = User::findOrFail($photographerId);
        $photographer->photographerProfile()->update([
            'average_rating' => round($average, 2),
            'total_ratings'  => $total,
        ]);
    }
}
