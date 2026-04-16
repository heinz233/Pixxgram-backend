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
    // POST /api/bookings  — client creates a booking
    // ─────────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'photographer_id' => 'required|integer|exists:users,id',
            // Use after:now with 1-hour buffer to avoid timezone issues
            'booking_date'    => 'required|date|after:+1 hour',
            'notes'           => 'nullable|string|max:1000',
        ]);

        $clientId       = Auth::id();
        $photographerId = (int) $request->photographer_id;

        // Cannot book yourself
        if ($clientId === $photographerId) {
            return response()->json(['message' => 'You cannot book yourself.'], 422);
        }

        // Make sure the person being booked is actually a photographer
        $photographer = User::find($photographerId);
        if (!$photographer || !$photographer->isPhotographer()) {
            return response()->json(['message' => 'The selected user is not a photographer.'], 422);
        }

        // Prevent duplicate pending bookings for the same photographer on the same day
        $sameDay = Booking::where('client_id', $clientId)
            ->where('photographer_id', $photographerId)
            ->where('status', 'pending')
            ->whereDate('booking_date', date('Y-m-d', strtotime($request->booking_date)))
            ->exists();

        if ($sameDay) {
            return response()->json([
                'message' => 'You already have a pending booking with this photographer on that day.',
            ], 409);
        }

        $booking = Booking::create([
            'client_id'       => $clientId,
            'photographer_id' => $photographerId,
            'booking_date'    => $request->booking_date,
            'notes'           => $request->notes,
            'status'          => 'pending',
        ]);

        return response()->json([
            'message' => 'Booking created successfully.',
            'booking' => $booking->load([
                'photographer:id,name,user_image',
                'client:id,name,user_image',
            ]),
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/bookings  — list bookings for the authenticated user
    // ─────────────────────────────────────────────────────────────────
   public function getBookings()
{
    /** @var \App\Models\User $user */
    $user = Auth::user();
    $user->loadMissing('role');

    if ($user->role_id === 3) {
        // Client
        $bookings = Booking::where('client_id', $user->id)
            ->with(['photographer:id,name,user_image,phoneNumber'])
            ->orderBy('booking_date', 'desc')
            ->get();
    } elseif ($user->role_id === 2) {
        // Photographer
        $bookings = Booking::where('photographer_id', $user->id)
            ->with(['client:id,name,user_image,phoneNumber'])
            ->orderBy('booking_date', 'desc')
            ->get();
    } else {
        // Admin
        $bookings = Booking::with([
                'client:id,name,user_image',
                'photographer:id,name,user_image',
            ])
            ->orderBy('booking_date', 'desc')
            ->paginate(50);

        return response()->json($bookings);
    }

    return response()->json($bookings);
}
    // ─────────────────────────────────────────────────────────────────
    // PATCH /api/bookings/{id}/status
    // ─────────────────────────────────────────────────────────────────
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,confirmed,completed,cancelled',
        ]);

        $booking = Booking::findOrFail($id);
        $userId  = Auth::id();

        // Only the client or photographer on this booking can update it
        if ($booking->client_id !== $userId && $booking->photographer_id !== $userId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Business rules:
        // - Client can only cancel a pending booking
        // - Photographer can confirm, complete, or cancel
        if ($booking->client_id === $userId && $request->status !== 'cancelled') {
            return response()->json([
                'message' => 'Clients can only cancel bookings.',
            ], 422);
        }

        $booking->update(['status' => $request->status]);

        return response()->json([
            'message' => 'Booking status updated.',
            'booking' => $booking->load([
                'client:id,name,user_image',
                'photographer:id,name,user_image',
            ]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/bookings/{id}
    // ─────────────────────────────────────────────────────────────────
    public function show($id)
    {
        $booking = Booking::with([
            'client:id,name,user_image,phoneNumber',
            'photographer:id,name,user_image,phoneNumber',
        ])->findOrFail($id);

        $userId = Auth::id();

        if ($booking->client_id !== $userId && $booking->photographer_id !== $userId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json($booking);
    }
}