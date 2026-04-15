<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /** POST /bookings */
    public function store(Request $request)
    {
        $request->validate([
            'photographer_id' => 'required|exists:users,id',
            'booking_date'    => 'required|date|after:now',
            'notes'           => 'nullable|string|max:1000',
        ]);

        // Prevent booking yourself
        if ($request->photographer_id == auth()->id()) {
            return response()->json(['error' => 'You cannot book yourself.'], 422);
        }

        $booking = Booking::create([
            'client_id'       => auth()->id(),
            'photographer_id' => $request->photographer_id,
            'booking_date'    => $request->booking_date,
            'notes'           => $request->notes,
            'status'          => 'pending',
        ]);

        return response()->json([
            'message' => 'Booking created successfully.',
            'booking' => $booking->load(['photographer:id,name,user_image']),
        ], 201);
    }

    /** PATCH /bookings/{id}/status */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,confirmed,completed,cancelled',
        ]);

        $booking = Booking::findOrFail($id);

        if ($booking->client_id != auth()->id() && $booking->photographer_id != auth()->id()) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        $booking->update(['status' => $request->status]);

        return response()->json([
            'message' => 'Booking status updated.',
            'booking' => $booking,
        ]);
    }

    /** GET /bookings */
    public function getBookings()
    {
        $user = auth()->user();

        // Fix: $user->isClient() / $user->isPhotographer() now exist on User model.
        if ($user->isClient()) {
            $bookings = Booking::where('client_id', $user->id)
                ->with('photographer:id,name,user_image')
                ->orderBy('booking_date', 'desc')
                ->get();
        } else {
            $bookings = Booking::where('photographer_id', $user->id)
                ->with('client:id,name,user_image')
                ->orderBy('booking_date', 'desc')
                ->get();
        }

        return response()->json($bookings);
    }

    /** GET /bookings/{id} */
    public function show($id)
    {
        $booking = Booking::findOrFail($id);

        if ($booking->client_id != auth()->id() && $booking->photographer_id != auth()->id()) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        return response()->json($booking->load(['client:id,name,user_image', 'photographer:id,name,user_image']));
    }
}
