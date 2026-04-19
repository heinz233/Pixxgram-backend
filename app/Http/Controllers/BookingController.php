<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\User;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookingController extends Controller
{
    public function __construct(protected MpesaService $mpesaService)
    {
        $this->middleware('auth:sanctum');
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/bookings — client creates a booking
    // ─────────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'photographer_id' => 'required|integer|exists:users,id',
            'booking_date'    => 'required|date|after:+1 hour',
            'notes'           => 'nullable|string|max:1000',
        ]);

        /** @var \App\Models\User $user */
        $user           = Auth::user();
        $clientId       = $user->id;
        $photographerId = (int) $request->photographer_id;

        if ($clientId === $photographerId) {
            return response()->json(['message' => 'You cannot book yourself.'], 422);
        }

        $photographer = User::find($photographerId);
        if (!$photographer || $photographer->role_id !== 2) {
            return response()->json(['message' => 'The selected user is not a photographer.'], 422);
        }

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
            'status'          => Booking::STATUS_PENDING,
            'payment_status'  => Booking::PAYMENT_UNPAID,
        ]);

        $booking->load([
            'client:id,name,user_image,phoneNumber',
            'photographer:id,name,user_image,phoneNumber',
            'photographer.photographerProfile:user_id,hourly_rate,location',
        ]);

        return response()->json([
            'message' => 'Booking request sent. The photographer will review and confirm.',
            'booking' => $booking,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/bookings
    // ─────────────────────────────────────────────────────────────────
    public function getBookings()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->role_id === 3) {
            // Client
            $bookings = Booking::where('client_id', $user->id)
                ->with([
                    'photographer:id,name,user_image,phoneNumber',
                    'photographer.photographerProfile:user_id,hourly_rate,location',
                ])
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
            return response()->json(
                Booking::with(['client:id,name', 'photographer:id,name'])
                    ->orderBy('booking_date', 'desc')
                    ->paginate(50)
            );
        }

        return response()->json($bookings);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/bookings/{id}
    // ─────────────────────────────────────────────────────────────────
    public function show($id)
    {
        $booking = Booking::with([
            'client:id,name,user_image,phoneNumber',
            'photographer:id,name,user_image,phoneNumber',
            'photographer.photographerProfile:user_id,hourly_rate,location,bio',
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

        if ($booking->client_id !== $userId && $booking->photographer_id !== $userId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $newStatus = $request->status;

        // Client rules
        if ($booking->client_id === $userId) {
            if ($newStatus !== 'cancelled') {
                return response()->json(['message' => 'Clients can only cancel bookings.'], 422);
            }
            if (!$booking->isPending()) {
                return response()->json(['message' => 'Only pending bookings can be cancelled.'], 422);
            }
        }

        // Photographer rules
        if ($booking->photographer_id === $userId) {
            if ($booking->isCancelled()) {
                return response()->json(['message' => 'Cannot update a cancelled booking.'], 422);
            }
            if ($newStatus === 'completed') {
                if (!$booking->isConfirmed()) {
                    return response()->json(['message' => 'Booking must be confirmed before completing.'], 422);
                }
                // Require payment before completing
                if (!$booking->isPaid()) {
                    return response()->json([
                        'message' => 'Payment must be received before marking booking as completed.',
                    ], 422);
                }
            }
            // When confirming, set payment as required
            if ($newStatus === 'confirmed') {
                $booking->update([
                    'status'         => 'confirmed',
                    'payment_status' => Booking::PAYMENT_UNPAID,
                ]);
                $booking->load(['client:id,name,user_image', 'photographer:id,name,user_image']);
                return response()->json([
                    'message'          => 'Booking confirmed! The client will be prompted to pay.',
                    'booking'          => $booking,
                    'payment_required' => true,
                ]);
            }
        }

        $booking->update(['status' => $newStatus]);
        $booking->load(['client:id,name', 'photographer:id,name']);

        return response()->json([
            'message' => 'Booking ' . $newStatus . '.',
            'booking' => $booking,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/bookings/{id}/pay — client initiates M-Pesa payment
    // ─────────────────────────────────────────────────────────────────
    public function initiatePayment(Request $request, $id)
    {
        $request->validate([
            'phone'  => 'required|string',
            'amount' => 'required|numeric|min:1',
        ]);

        $booking = Booking::findOrFail($id);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($booking->client_id !== $user->id) {
            return response()->json(['message' => 'Only the client can initiate payment.'], 403);
        }

        if (!$booking->isConfirmed()) {
            return response()->json([
                'message' => 'The photographer must confirm the booking before payment.',
            ], 422);
        }

        if ($booking->isPaid()) {
            return response()->json(['message' => 'This booking has already been paid.'], 409);
        }

        $amount = (int) $request->amount;

        $stkResult = $this->mpesaService->stkPush(
            phone:       $request->phone,
            amount:      $amount,
            reference:   'PIXXGRAM-BK-' . $booking->id,
            description: 'Pixxgram Booking Payment',
        );

        if (!$stkResult['success']) {
            return response()->json([
                'message' => $stkResult['message'] ?? 'M-Pesa payment failed to initiate.',
            ], 422);
        }

        $booking->update([
            'amount'                    => $amount,
            'payment_status'            => Booking::PAYMENT_PENDING_PAYMENT,
            'mpesa_checkout_request_id' => $stkResult['checkout_request_id'],
        ]);

        return response()->json([
            'message'             => 'M-Pesa prompt sent. Enter your PIN to complete payment.',
            'checkout_request_id' => $stkResult['checkout_request_id'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/bookings/{id}/payment-status — poll payment
    // ─────────────────────────────────────────────────────────────────
    public function paymentStatus($id)
    {
        $booking = Booking::findOrFail($id);

        /** @var \App\Models\User $user */
        $user = Auth::user();
        if ($booking->client_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'payment_status' => $booking->payment_status,
            'mpesa_receipt'  => $booking->mpesa_receipt,
            'amount'         => $booking->amount,
            'paid_at'        => $booking->paid_at,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/bookings/mpesa/callback — Safaricom webhook (public)
    // ─────────────────────────────────────────────────────────────────
    public function mpesaCallback(Request $request)
    {
        // Always respond 200 immediately
        response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted'])->send();

        $body = $request->input('Body.stkCallback');
        if (!$body) return;

        $checkoutId = $body['CheckoutRequestID'] ?? null;
        $resultCode = $body['ResultCode'] ?? 1;

        $booking = Booking::where('mpesa_checkout_request_id', $checkoutId)->first();
        if (!$booking) return;

        if ($resultCode !== 0) {
            $booking->update(['payment_status' => Booking::PAYMENT_UNPAID]);
            return;
        }

        $meta    = collect($body['CallbackMetadata']['Item'] ?? [])->pluck('Value', 'Name');
        $receipt = $meta->get('MpesaReceiptNumber');

        $booking->update([
            'payment_status' => Booking::PAYMENT_PAID,
            'mpesa_receipt'  => $receipt,
            'paid_at'        => now(),
        ]);
    }
}
