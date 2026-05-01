<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Portfolio;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PhotographerProfileController extends Controller
{
    private function requirePhotographer()
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if (!$user || $user->role_id !== 2) {
            abort(response()->json(['message' => 'Only photographers can perform this action.'], 403));
        }
        return $user;
    }

    // GET /api/photographers  (public)
    public function index(Request $request)
    {
        $query = User::where('role_id', 2)->with('photographerProfile');

        // Remove status filter during development so all photographers show.
        // Uncomment for production: ->where('status', 'active')->where('is_active', true)

        if ($request->location) {
            $query->whereHas('photographerProfile', fn($q) =>
                $q->where('location', 'like', '%' . $request->location . '%'));
        }
        if ($request->gender) {
            $query->whereHas('photographerProfile', fn($q) =>
                $q->where('gender', $request->gender));
        }
        if ($request->min_price) {
            $query->whereHas('photographerProfile', fn($q) =>
                $q->where('hourly_rate', '>=', $request->min_price));
        }
        if ($request->max_price) {
            $query->whereHas('photographerProfile', fn($q) =>
                $q->where('hourly_rate', '<=', $request->max_price));
        }
        if ($request->min_rating) {
            $query->whereHas('photographerProfile', fn($q) =>
                $q->where('average_rating', '>=', $request->min_rating));
        }

        return response()->json($query->paginate(20));
    }

    // GET /api/photographers/{id}  (public)
    public function show($id)
    {
        $photographer = User::where('id', $id)
            ->where('role_id', 2)
            ->with([
                'photographerProfile',
                'portfolios',
                'ratingsReceived.client:id,name,user_image',
            ])
            ->first();

        if (!$photographer) {
            return response()->json(['message' => 'Photographer not found.'], 404);
        }

        return response()->json($photographer);
    }

    // PUT /api/photographer/profile  (photographer only)
    // POST /api/photographer/profile  (for multipart FormData support)
    public function updateProfile(Request $request)
    {
        $user = $this->requirePhotographer();

        $request->validate([
            'age'           => 'sometimes|integer|min:18|max:100',
            'gender'        => 'sometimes|in:male,female,other',
            'location'      => 'sometimes|string|max:255',
            'bio'           => 'sometimes|string|max:2000',
            'hourly_rate'   => 'sometimes|numeric|min:0',
            'profile_photo' => 'sometimes|image|mimes:jpeg,png,jpg,webp|max:4096',
        ]);

        $data = $request->only(['age', 'gender', 'location', 'bio', 'hourly_rate']);

        foreach (['bio', 'location', 'gender'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field) ?: null;
            }
        }

        if ($request->hasFile('profile_photo')) {
            $existing = $user->photographerProfile?->profile_photo;
            if ($existing) Storage::disk('public')->delete($existing);
            $data['profile_photo'] = $request->file('profile_photo')->store('profiles', 'public');
        }

        $user->photographerProfile()->updateOrCreate(['user_id' => $user->id], $data);

        $freshUser = $user->fresh()->load(['role', 'photographerProfile']);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $freshUser,
            'profile' => $freshUser->photographerProfile,
        ]);
    }

    // POST /api/photographer/portfolio  (photographer only)
    public function uploadPortfolio(Request $request)
    {
        $user = $this->requirePhotographer();

        $request->validate([
            'images'      => 'required|array|max:10',
            'images.*'    => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category'    => 'required|string|max:100',
        ]);

        $uploaded = [];
        foreach ($request->file('images') as $image) {
            $path          = $image->store('portfolio', 'public');
            $thumbnailPath = $this->createThumbnail($image, $path);
            $uploaded[] = Portfolio::create([
                'photographer_id' => $user->id,
                'title'           => $request->title,
                'description'     => $request->description,
                'image_url'       => $path,
                'thumbnail_url'   => $thumbnailPath ?? null,
                'category'        => $request->category,
                'tags'            => $request->tags ?? [],
            ]);
        }

        return response()->json([
            'message' => count($uploaded) . ' photo(s) uploaded successfully.',
            'images'  => $uploaded,
        ], 201);
    }

    // GET /api/photographer/portfolio  (photographer only)
    public function getPortfolio()
    {
        $user = $this->requirePhotographer();
        $portfolios = Portfolio::where('photographer_id', $user->id)->latest()->get();
        return response()->json(['portfolios' => $portfolios]);
    }

    // DELETE /api/photographer/portfolio/{id}  (photographer only)
    public function deletePortfolioItem($id)
    {
        $user = $this->requirePhotographer();
        $item = Portfolio::where('id', $id)->where('photographer_id', $user->id)->firstOrFail();

        foreach (['image_url', 'thumbnail_url'] as $field) {
            if ($item->$field) {
                $path = str_replace('/storage/', '', parse_url($item->$field, PHP_URL_PATH));
                Storage::disk('public')->delete($path);
            }
        }

        $item->delete();
        return response()->json(['message' => 'Portfolio item deleted successfully.']);
    }

    // GET /api/photographer/dashboard  (photographer only)
    public function getDashboard()
    {
        $user = $this->requirePhotographer();
        return response()->json([
            'subscription_status'   => $user->photographerProfile?->subscription_status,
            'subscription_end_date' => $user->photographerProfile?->subscription_end_date,
            'profile_completion'    => $this->calculateProfileCompletion($user),
            'average_rating'        => $user->photographerProfile?->average_rating,
            'total_ratings'         => $user->photographerProfile?->total_ratings,
            'upcoming_bookings'     => \App\Models\Booking::where('photographer_id', $user->id)
                ->where('booking_date', '>', now())
                ->where('status', 'confirmed')->count(),
            'portfolio_analysis'    => Portfolio::where('photographer_id', $user->id)
                ->select('id', 'title', 'category', 'views', 'image_url', 'thumbnail_url')
                ->latest()->get(),
        ]);
    }

    private function calculateProfileCompletion(User $user): int
    {
        $profile = $user->photographerProfile;
        if (!$profile) return 0;
        $fields    = ['bio', 'gender', 'location', 'profile_photo', 'hourly_rate'];
        $completed = collect($fields)->filter(fn($f) => !empty($profile->$f))->count();
        return (int) round(($completed / count($fields)) * 100);
    }

    private function createThumbnail($file, string $originalPath): ?string
    {
        try {
            $ext = strtolower($file->getClientOriginalExtension());
            $src = match($ext) {
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

            if ($ext === 'png') {
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                $t = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
                imagefilledrectangle($thumb, 0, 0, 300, 300, $t);
            }

            imagecopyresampled($thumb, $src, 0, 0,
                (int)(($w - $size) / 2), (int)(($h - $size) / 2),
                300, 300, $size, $size);

            $thumbPath = 'thumbnails/' . basename($originalPath);
            $fullPath  = storage_path('app/public/' . $thumbPath);
            if (!is_dir(dirname($fullPath))) mkdir(dirname($fullPath), 0755, true);
            imagejpeg($thumb, $fullPath, 85);

            return $thumbPath;
        } catch (\Throwable $e) {
            Log::warning('Thumbnail failed: ' . $e->getMessage());
            return null;
        }
    }
}