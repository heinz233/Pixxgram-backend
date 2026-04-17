<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookingController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/bookings
    // Client creates a booking request for a photographer
    // ─────────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'photographer_id' => 'required|integer|exists:users,id',
            'booking_date'    => 'required|date|after:+1 hour',
            'notes'           => 'nullable|string|max:1000',
        ]);

        /** @var \App\Models\User $authUser */
        $authUser       = Auth::user();
        $clientId       = $authUser->id;
        $photographerId = (int) $request->photographer_id;

        // Prevent booking yourself
        if ($clientId === $photographerId) {
            return response()->json([
                'message' => 'You cannot book yourself.',
            ], 422);
        }

        // Ensure the target user is actually a photographer (role_id = 2)
        $photographer = User::find($photographerId);
        if (!$photographer || $photographer->role_id !== 2) {
            return response()->json([
                'message' => 'The selected user is not a photographer.',
            ], 422);
        }

        // Prevent duplicate pending bookings on the same day
        $duplicate = Booking::where('client_id', $clientId)
            ->where('photographer_id', $photographerId)
            ->where('status', 'pending')
            ->whereDate('booking_date', date('Y-m-d', strtotime($request->booking_date)))
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'You already have a pending booking with this photographer on that day.',
            ], 409);
        }

        $booking = Booking::create([
            'client_id'       => $clientId,
            'photographer_id' => $photographerId,
            'booking_date'    => $request->booking_date,
            'notes'           => $request->notes ?? null,
            'status'          => 'pending',
        ]);

        $booking->load([
            'client:id,name,user_image,phoneNumber',
            'photographer:id,name,user_image,phoneNumber',
        ]);

        return response()->json([
            'message' => 'Booking request sent successfully.',
            'booking' => $booking,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/bookings
    // Returns bookings for the authenticated user (role-aware)
    // ─────────────────────────────────────────────────────────────────
    public function getBookings()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        // Client (role_id = 3) — bookings they created
        if ($user->role_id === 3) {
            $bookings = Booking::where('client_id', $user->id)
                ->with([
                    'photographer:id,name,user_image,phoneNumber',
                    'photographer.photographerProfile:user_id,location,hourly_rate,average_rating',
                ])
                ->orderBy('booking_date', 'desc')
                ->get();

            return response()->json($bookings);
        }

        // Photographer (role_id = 2) — bookings made for them
        if ($user->role_id === 2) {
            $bookings = Booking::where('photographer_id', $user->id)
                ->with(['client:id,name,user_image,phoneNumber'])
                ->orderBy('booking_date', 'desc')
                ->get();

            return response()->json($bookings);
        }

        // Admin (role_id = 1) — all bookings paginated
        $bookings = Booking::with([
                'client:id,name,user_image',
                'photographer:id,name,user_image',
            ])
            ->orderBy('booking_date', 'desc')
            ->paginate(50);

        return response()->json($bookings);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/bookings/{id}
    // Single booking — must belong to the auth user
    // ─────────────────────────────────────────────────────────────────
    public function show($id)
    {
        $booking = Booking::with([
            'client:id,name,user_image,phoneNumber',
            'photographer:id,name,user_image,phoneNumber',
            'photographer.photographerProfile:user_id,location,hourly_rate,bio',
        ])->findOrFail($id);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($booking->client_id !== $user->id && $booking->photographer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json($booking);
    }

    // ─────────────────────────────────────────────────────────────────
    // PATCH /api/bookings/{id}/status
    // Update booking status with role-based rules
    // ─────────────────────────────────────────────────────────────────
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,confirmed,completed,cancelled',
        ]);

        $booking = Booking::findOrFail($id);

        /** @var \App\Models\User $user */
        $user   = Auth::user();
        $userId = $user->id;

        // Must be the client or photographer on this booking
        if ($booking->client_id !== $userId && $booking->photographer_id !== $userId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $newStatus = $request->status;

        // Client rules: can only cancel, and only if still pending
        if ($booking->client_id === $userId) {
            if ($newStatus !== 'cancelled') {
                return response()->json([
                    'message' => 'Clients can only cancel bookings.',
                ], 422);
            }
            if ($booking->status !== 'pending') {
                return response()->json([
                    'message' => 'You can only cancel a pending booking.',
                ], 422);
            }
        }

        // Photographer rules
        if ($booking->photographer_id === $userId) {
            if ($booking->status === 'cancelled') {
                return response()->json([
                    'message' => 'Cannot update a cancelled booking.',
                ], 422);
            }
            if ($newStatus === 'completed' && $booking->status !== 'confirmed') {
                return response()->json([
                    'message' => 'Booking must be confirmed before marking as completed.',
                ], 422);
            }
        }

        $booking->update(['status' => $newStatus]);

        $booking->load([
            'client:id,name,user_image',
            'photographer:id,name,user_image',
        ]);

        return response()->json([
            'message' => 'Booking ' . $newStatus . '.',
            'booking' => $booking,
        ]);
    }
}