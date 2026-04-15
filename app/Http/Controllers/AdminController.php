<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Report;
use App\Models\Category;
use App\Models\Location;
use App\Models\Subscription;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');   // Fix: was auth:api (Passport) — project uses Sanctum
        $this->middleware('role:admin');
    }

    // -----------------------------------------------------------------
    // Photographers
    // -----------------------------------------------------------------

    public function getPhotographers(Request $request)
    {
        $photographers = User::where('role_id', 2)  // Fix: was where('role','photographer') — project uses role_id FK
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

        $photographer = User::findOrFail($id);

        if (!$photographer->isPhotographer()) {
            return response()->json(['error' => 'User is not a photographer.'], 400);
        }

        $photographer->update(['status' => $request->status]);

        return response()->json(['message' => 'Photographer status updated successfully.']);
    }

    // -----------------------------------------------------------------
    // Reports
    // -----------------------------------------------------------------

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
        $report = Report::findOrFail($id);
        $report->update(['status' => 'resolved']);

        return response()->json(['message' => 'Report resolved successfully.']);
    }

    public function dismissReport($id)
    {
        $report = Report::findOrFail($id);
        $report->update(['status' => 'dismissed']);

        return response()->json(['message' => 'Report dismissed successfully.']);
    }

    // -----------------------------------------------------------------
    // Subscriptions
    // -----------------------------------------------------------------

    public function manageSubscription(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|in:force_delete,reactivate',
        ]);

        $photographer = User::findOrFail($id);

        if (!$photographer->isPhotographer()) {
            return response()->json(['error' => 'User is not a photographer.'], 400);
        }

        if ($request->action === 'force_delete') {
            $photographer->photographerProfile()->update([
                'subscription_status'   => 'expired',
                'subscription_end_date' => null,
            ]);
            $photographer->update(['status' => 'suspended']);

            return response()->json(['message' => 'Subscription force-deleted successfully.']);
        }

        if ($request->action === 'reactivate') {
            $photographer->photographerProfile()->update([
                'subscription_status'   => 'active',
                'subscription_end_date' => now()->addMonth(),
            ]);
            $photographer->update(['status' => 'active']);

            return response()->json(['message' => 'Subscription reactivated successfully.']);
        }
    }

    // -----------------------------------------------------------------
    // Locations
    // -----------------------------------------------------------------

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
            return response()->json(Location::create($request->only(['name', 'region'])), 201);
        }

        if ($request->isMethod('put') || $request->isMethod('patch')) {
            if (!$id) return response()->json(['error' => 'Location ID is required.'], 400);

            $request->validate([
                'name'   => 'sometimes|string|unique:locations,name,' . $id,
                'region' => 'nullable|string',
            ]);

            $location = Location::findOrFail($id);
            $location->update($request->only(['name', 'region']));
            return response()->json($location);
        }

        if ($request->isMethod('delete')) {
            if (!$id) return response()->json(['error' => 'Location ID is required.'], 400);

            Location::findOrFail($id)->delete();
            return response()->json(['message' => 'Location deleted successfully.']);
        }

        return response()->json(['error' => 'Method not allowed.'], 405);
    }

    // -----------------------------------------------------------------
    // Categories
    // -----------------------------------------------------------------

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
            if (!$id) return response()->json(['error' => 'Category ID is required.'], 400);

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
            if (!$id) return response()->json(['error' => 'Category ID is required.'], 400);

            Category::findOrFail($id)->delete();
            return response()->json(['message' => 'Category deleted successfully.']);
        }

        return response()->json(['error' => 'Method not allowed.'], 405);
    }

    // -----------------------------------------------------------------
    // Dashboard Stats
    // -----------------------------------------------------------------

    public function getDashboardStats()
    {
        $stats = [
            'total_photographers'  => User::where('role_id', 2)->count(),
            'active_photographers' => User::where('role_id', 2)
                ->where('status', 'active')
                ->whereHas('photographerProfile', fn($q) =>
                    $q->where('subscription_status', 'active')
                )
                ->count(),
            'total_clients'        => User::where('role_id', 3)->count(),
            'pending_reports'      => Report::where('status', 'pending')->count(),
            'total_revenue'        => Subscription::where('status', 'active')->sum('amount'),
            'monthly_revenue'      => Subscription::where('status', 'active')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('amount'),
            'total_bookings'       => Booking::count(),
            'completed_bookings'   => Booking::where('status', 'completed')->count(),
        ];

        return response()->json($stats);
    }
}
