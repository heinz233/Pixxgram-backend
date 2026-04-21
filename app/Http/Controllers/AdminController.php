<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Report;
use App\Models\Category;
use App\Models\Location;
use App\Models\Subscription;
use App\Models\Booking;
use App\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('role:admin');
    }

    // ─────────────────────────────────────────────────────────────────
    // Dashboard Stats
    // ─────────────────────────────────────────────────────────────────
    public function getDashboardStats()
    {
        // Active subscriptions: check photographer_profiles as source of truth
        // (subscriptions table rows may be pending/cancelled even when profile is active)
        $activeSubscriptions = \App\Models\PhotographerProfile::where('subscription_status', 'active')
            ->where('subscription_end_date', '>', now())
            ->count();

        // Revenue: count ALL subscriptions that had a real payment
        // (active + any that were paid before being cancelled/expired)
        $totalRevenue = Subscription::whereIn('status', ['active', 'cancelled', 'expired'])
            ->where('amount', '>', 0)
            ->sum('amount');

        $monthlyRevenue = Subscription::whereIn('status', ['active', 'cancelled', 'expired'])
            ->where('amount', '>', 0)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('amount');

        $stats = [
            'total_photographers'  => User::where('role_id', 2)->count(),
            'active_photographers' => $activeSubscriptions,
            'total_clients'        => User::where('role_id', 3)->count(),
            'pending_reports'      => Report::where('status', 'pending')->count(),
            'total_revenue'        => $totalRevenue,
            'monthly_revenue'      => $monthlyRevenue,
            'total_bookings'       => Booking::count(),
            'completed_bookings'   => Booking::where('status', 'completed')->count(),
        ];

        return response()->json($stats);
    }

    // ─────────────────────────────────────────────────────────────────
    // Photographers
    // ─────────────────────────────────────────────────────────────────
    public function getPhotographers(Request $request)
    {
        $photographers = User::where('role_id', 2)
            ->with('photographerProfile')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->paginate(20);

        return response()->json($photographers);
    }

    public function updatePhotographerStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:active,suspended,banned',
        ]);

        $user = User::findOrFail($id);
        $user->update([
            'status'    => $request->status,
            'is_active' => $request->status === 'active',
        ]);

        return response()->json(['message' => 'Status updated to ' . $request->status]);
    }

    public function manageSubscription(Request $request, $id)
    {
        $request->validate(['action' => 'required|in:force_delete,reactivate']);

        $photographer = User::findOrFail($id);

        if ($request->action === 'force_delete') {
            $photographer->photographerProfile()->update([
                'subscription_status'   => 'expired',
                'subscription_end_date' => null,
            ]);
            $photographer->update(['status' => 'suspended', 'is_active' => false]);
            return response()->json(['message' => 'Subscription force-deleted.']);
        }

        $photographer->photographerProfile()->update([
            'subscription_status'   => 'active',
            'subscription_end_date' => now()->addMonth(),
        ]);
        $photographer->update(['status' => 'active', 'is_active' => true]);
        return response()->json(['message' => 'Subscription reactivated.']);
    }

    // ─────────────────────────────────────────────────────────────────
    // All Users
    // ─────────────────────────────────────────────────────────────────
    public function getUsers(Request $request)
    {
        $users = User::with('role')
            ->when($request->role, fn($q, $r) =>
                $q->whereHas('role', fn($rq) => $rq->where('name', $r))
            )
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($users);
    }

    public function toggleUserActive($id)
    {
        $user = User::findOrFail($id);
        $user->is_active = !$user->is_active;
        if ($user->is_active && $user->status === 'suspended') {
            $user->status = 'active';
        }
        $user->save();

        return response()->json([
            'message'   => 'Account ' . ($user->is_active ? 'activated' : 'deactivated'),
            'is_active' => $user->is_active,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Reports
    // ─────────────────────────────────────────────────────────────────
    public function getReports(Request $request)
    {
        $reports = Report::with(['client:id,name,email', 'photographer:id,name,email'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($reports);
    }

    public function resolveReport($id)
    {
        Report::findOrFail($id)->update(['status' => 'resolved']);
        return response()->json(['message' => 'Report resolved.']);
    }

    public function dismissReport($id)
    {
        Report::findOrFail($id)->update(['status' => 'dismissed']);
        return response()->json(['message' => 'Report dismissed.']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Ratings — admin can view all and delete
    // ─────────────────────────────────────────────────────────────────
    public function getRatings(Request $request)
    {
        $ratings = Rating::with([
                'client:id,name,user_image',
                'photographer:id,name,user_image',
            ])
            ->when($request->stars, fn($q, $s) => $q->where('stars', $s))
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($ratings);
    }

    public function deleteRating($id)
    {
        $rating = Rating::findOrFail($id);

        // Recalculate photographer's average after deletion
        $photographerId = $rating->photographer_id;
        $rating->delete();

        $average = Rating::where('photographer_id', $photographerId)->avg('stars') ?? 0;
        $total   = Rating::where('photographer_id', $photographerId)->count();

        User::findOrFail($photographerId)
            ->photographerProfile()
            ->update([
                'average_rating' => round($average, 2),
                'total_ratings'  => $total,
            ]);

        return response()->json(['message' => 'Rating removed and averages recalculated.']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Subscriptions list
    // ─────────────────────────────────────────────────────────────────
    public function getSubscriptions(Request $request)
    {
        $subs = Subscription::with('photographer:id,name,email')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->plan,   fn($q, $p) => $q->where('plan', $p))
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        // Augment each subscription with whether photographer profile is active
        $subs->getCollection()->transform(function ($sub) {
            $profile = \App\Models\PhotographerProfile::where('user_id', $sub->photographer_id)->first();
            $sub->profile_subscription_status   = $profile?->subscription_status;
            $sub->profile_subscription_end_date = $profile?->subscription_end_date;
            // Mark as effectively active if profile says so, even if subscription row says otherwise
            $sub->is_effectively_active =
                $profile?->subscription_status === 'active' &&
                $profile?->subscription_end_date &&
                $profile->subscription_end_date > now();
            return $sub;
        });

        // Summary stats for the revenue cards
        $allSubs   = Subscription::whereIn('status', ['active','cancelled','expired'])->where('amount', '>', 0)->get();
        $now       = now();
        $activePro = \App\Models\PhotographerProfile::where('subscription_status', 'active')
            ->where('subscription_end_date', '>', $now)->count();

        return response()->json([
            'data'           => $subs,
            'summary' => [
                'total_revenue'        => $allSubs->sum('amount'),
                'active_subscriptions' => $activePro,
                'month_revenue'        => $allSubs
                    ->filter(fn($s) =>
                        \Carbon\Carbon::parse($s->created_at)->month === $now->month &&
                        \Carbon\Carbon::parse($s->created_at)->year  === $now->year
                    )->sum('amount'),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Bookings list (for activity feed)
    // ─────────────────────────────────────────────────────────────────
    public function getBookings(Request $request)
    {
        $bookings = Booking::with([
                'client:id,name',
                'photographer:id,name',
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($bookings);
    }

    // ─────────────────────────────────────────────────────────────────
    // Locations
    // ─────────────────────────────────────────────────────────────────
    public function manageLocations(Request $request, $id = null)
    {
        if ($request->isMethod('get')) {
            return response()->json(Location::all());
        }

        if ($request->isMethod('post')) {
            $request->validate([
                'name'   => 'required|string|unique:locations',
                'region' => 'nullable|string',
            ]);
            return response()->json(
                Location::create($request->only(['name', 'region'])),
                201
            );
        }

        if ($request->isMethod('put') || $request->isMethod('patch')) {
            if (!$id) return response()->json(['error' => 'Location ID required.'], 400);
            $request->validate([
                'name'   => 'sometimes|string|unique:locations,name,' . $id,
                'region' => 'nullable|string',
            ]);
            $location = Location::findOrFail($id);
            $location->update($request->only(['name', 'region']));
            return response()->json($location);
        }

        if ($request->isMethod('delete')) {
            if (!$id) return response()->json(['error' => 'Location ID required.'], 400);
            Location::findOrFail($id)->delete();
            return response()->json(['message' => 'Location deleted.']);
        }

        return response()->json(['error' => 'Method not allowed.'], 405);
    }

    // ─────────────────────────────────────────────────────────────────
    // Categories
    // ─────────────────────────────────────────────────────────────────
    public function manageCategories(Request $request, $id = null)
    {
        if ($request->isMethod('get')) {
            return response()->json(Category::all());
        }

        if ($request->isMethod('post')) {
            $request->validate([
                'name'        => 'required|string|unique:categories',
                'slug'        => 'nullable|string|unique:categories',
                'description' => 'nullable|string',
            ]);
            $category = Category::create([
                'name'        => $request->name,
                'slug'        => $request->slug ?: Str::slug($request->name),
                'description' => $request->description,
            ]);
            return response()->json($category, 201);
        }

        if ($request->isMethod('put') || $request->isMethod('patch')) {
            if (!$id) return response()->json(['error' => 'Category ID required.'], 400);
            $request->validate([
                'name'        => 'sometimes|string|unique:categories,name,' . $id,
                'slug'        => 'sometimes|string|unique:categories,slug,' . $id,
                'description' => 'nullable|string',
            ]);
            $category = Category::findOrFail($id);
            if ($request->has('name') && !$request->has('slug')) {
                $request->merge(['slug' => Str::slug($request->name)]);
            }
            $category->update($request->only(['name', 'slug', 'description']));
            return response()->json($category);
        }

        if ($request->isMethod('delete')) {
            if (!$id) return response()->json(['error' => 'Category ID required.'], 400);
            Category::findOrFail($id)->delete();
            return response()->json(['message' => 'Category deleted.']);
        }

        return response()->json(['error' => 'Method not allowed.'], 405);
    }
}