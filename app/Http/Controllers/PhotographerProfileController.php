<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Rating;
use Illuminate\Support\Facades\Log;
use App\Models\Portfolio;
use App\Models\Booking;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PhotographerProfileController extends Controller
{
    // ─────────────────────────────────────────────────────────────────
    // Helper: abort with JSON if user is not a photographer
    // ─────────────────────────────────────────────────────────────────
    private function requirePhotographer()
        {
            /** @var \App\Models\User|null $user */
            $user = auth('sanctum')->user();

            if (!$user || !$user->isPhotographer()) {
                abort(response()->json([
                    'message' => 'Only photographers can perform this action.',
                ], 403));
            }

            return $user;
        }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/photographers  (public)
    // ─────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $query = User::where('role_id', 2)
            ->where('status', 'active')
            ->with('photographerProfile');

        if ($request->location) {
            $query->whereHas('photographerProfile', fn($q) =>
                $q->where('location', 'like', '%' . $request->location . '%')
            );
        }
        if ($request->gender) {
            $query->whereHas('photographerProfile', fn($q) =>
                $q->where('gender', $request->gender)
            );
        }
        if ($request->min_age) {
            $query->whereHas('photographerProfile', fn($q) =>
                $q->where('age', '>=', $request->min_age)
            );
        }
        if ($request->max_age) {
            $query->whereHas('photographerProfile', fn($q) =>
                $q->where('age', '<=', $request->max_age)
            );
        }
        if ($request->min_price) {
            $query->whereHas('photographerProfile', fn($q) =>
                $q->where('hourly_rate', '>=', $request->min_price)
            );
        }
        if ($request->max_price) {
            $query->whereHas('photographerProfile', fn($q) =>
                $q->where('hourly_rate', '<=', $request->max_price)
            );
        }
        if ($request->min_rating) {
            $query->whereHas('photographerProfile', fn($q) =>
                $q->where('average_rating', '>=', $request->min_rating)
            );
        }
        if ($request->category) {
            $query->whereHas('portfolios', fn($q) =>
                $q->where('category', $request->category)
            );
        }

        return response()->json($query->paginate(20));
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/photographers/{id}  (public)
    // ─────────────────────────────────────────────────────────────────
    public function show($id)
    {
        $photographer = User::where('role_id', 2)
            ->with([
                'photographerProfile',
                'portfolios',
                'ratingsReceived.client:id,name,user_image',
            ])
            ->findOrFail($id);

        return response()->json($photographer);
    }

    // ─────────────────────────────────────────────────────────────────
    // PUT /api/photographer/profile  (photographer only)
    // ─────────────────────────────────────────────────────────────────
    public function updateProfile(Request $request)
    {
        $user = $this->requirePhotographer();
    
        $validated = $request->validate([
            'age'           => 'sometimes|integer|min:18|max:100',
            'gender'        => 'sometimes|in:male,female,other',
            'location'      => 'sometimes|string|max:255',
            'bio'           => 'sometimes|string|max:2000',
            'hourly_rate'   => 'sometimes|numeric|min:0',
            'service_rates' => 'sometimes|array',
            'profile_photo' => 'sometimes|image|mimes:jpeg,png,jpg,webp|max:4096',
        ]);
    
        if ($request->hasFile('profile_photo')) {
            // Delete the old photo file if one exists
            $existing = $user->photographerProfile?->profile_photo;
            if ($existing) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($existing);
            }
    
            // Store new photo and save the RELATIVE PATH (e.g. "profiles/abc.jpg")
            // NOT the full URL — we build the URL on the frontend
            $validated['profile_photo'] = $request->file('profile_photo')
                ->store('profiles', 'public');
        }
    
        // Allow clearing string fields (bio, location, gender) by sending empty string
        foreach (['bio', 'location', 'gender'] as $field) {
            if ($request->has($field)) {
                $validated[$field] = $request->input($field) ?: null;
            }
        }
    
        $user->photographerProfile()->updateOrCreate(
            ['user_id' => $user->id],
            $validated
        );
    
        // Return fresh user WITH the photographer_profile relationship loaded
        // so the Vue frontend can update its store in one round-trip
        $freshUser = $user->fresh()->load(['role', 'photographerProfile']);
    
        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $freshUser,
            'profile' => $freshUser->photographerProfile,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/photographer/portfolio  (photographer only)
    // ─────────────────────────────────────────────────────────────────
    public function uploadPortfolio(Request $request)
    {
        $user = $this->requirePhotographer();

        $request->validate([
            'images'      => 'required|array|max:10',
            'images.*'    => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category'    => 'required|string|max:100',
            'tags'        => 'nullable|array',
            'tags.*'      => 'string|max:50',
        ]);

        // Check subscription — only active subscribers can upload
        $profile = $user->photographerProfile;
        if (!$profile || !$profile->isSubscriptionActive()) {
            return response()->json([
                'message' => 'An active subscription is required to upload portfolio photos.',
            ], 403);
        }

        $uploaded = [];

        foreach ($request->file('images') as $image) {
            $path          = $image->store('portfolio', 'public');
            $thumbnailPath = $this->createThumbnail($image, $path);

            $uploaded[] = Portfolio::create([
                'photographer_id' => $user->id,
                'title'           => $request->title,
                'description'     => $request->description,
                'image_url'       => Storage::url($path),
                'thumbnail_url'   => $thumbnailPath ? Storage::url($thumbnailPath) : null,
                'category'        => $request->category,
                'tags'            => $request->tags ?? [],
            ]);
        }

        return response()->json([
            'message' => 'Portfolio uploaded successfully.',
            'images'  => $uploaded,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/photographer/dashboard  (photographer only)
    // ─────────────────────────────────────────────────────────────────
    public function getDashboard()
    {
        $user = $this->requirePhotographer();

        $stats = [
            'subscription_status'   => $user->photographerProfile?->subscription_status,
            'subscription_end_date' => $user->photographerProfile?->subscription_end_date,
            'profile_completion'    => $this->calculateProfileCompletion($user),
            'average_rating'        => $user->photographerProfile?->average_rating,
            'total_ratings'         => $user->photographerProfile?->total_ratings,
            'pending_reports'       => Report::where('photographer_id', $user->id)
                ->where('status', 'pending')->count(),
            'upcoming_bookings'     => Booking::where('photographer_id', $user->id)
                ->where('booking_date', '>', now())
                ->where('status', 'confirmed')->count(),
            'total_portfolio_views' => Portfolio::where('photographer_id', $user->id)->sum('views'),
            'total_portfolio_saves' => Portfolio::where('photographer_id', $user->id)->sum('saves'),
            'total_inquiries'       => Portfolio::where('photographer_id', $user->id)->sum('inquiries'),
            'portfolio_analysis'    => $this->getPortfolioAnalysis($user),
        ];

        return response()->json($stats);
    }

    // ─────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────

    private function calculateProfileCompletion(User $user): int
    {
        $profile = $user->photographerProfile;
        if (!$profile) return 0;

        $fields    = ['age', 'gender', 'location', 'bio', 'profile_photo', 'hourly_rate'];
        $completed = collect($fields)->filter(fn($f) => !empty($profile->$f))->count();

        return (int) round(($completed / count($fields)) * 100);
    }

    private function getPortfolioAnalysis(User $user)
    {
        return Portfolio::where('photographer_id', $user->id)
            ->select('id', 'title', 'category', 'views', 'saves', 'inquiries')
            ->latest()
            ->get();
    }

    private function createThumbnail($file, string $originalPath): ?string
    {
        try {
            $ext = strtolower($file->getClientOriginalExtension());
            $src = match ($ext) {
                'jpg', 'jpeg' => imagecreatefromjpeg($file->getRealPath()),
                'png'         => imagecreatefrompng($file->getRealPath()),
                'gif'         => imagecreatefromgif($file->getRealPath()),
                'webp'        => imagecreatefromwebp($file->getRealPath()),
                default       => null,
            };

            if (!$src) return null;

            [$w, $h] = [imagesx($src), imagesy($src)];
            $size    = min($w, $h);
            $thumb   = imagecreatetruecolor(300, 300);

            // Preserve transparency for PNG
            if ($ext === 'png') {
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
                imagefilledrectangle($thumb, 0, 0, 300, 300, $transparent);
            }

            imagecopyresampled(
                $thumb, $src,
                0, 0,
                (int)(($w - $size) / 2), (int)(($h - $size) / 2),
                300, 300,
                $size, $size
            );

            $thumbPath = 'thumbnails/' . basename($originalPath);
            $fullPath  = storage_path('app/public/' . $thumbPath);

            if (!is_dir(dirname($fullPath))) {
                mkdir(dirname($fullPath), 0755, true);
            }

            imagejpeg($thumb, $fullPath, 85);

            return $thumbPath;

        } catch (\Throwable $e) {
            Log::warning('Thumbnail creation failed: ' . $e->getMessage());
            return null;
        }
    }
}