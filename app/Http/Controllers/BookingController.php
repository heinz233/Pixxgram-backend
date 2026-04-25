<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\User;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function __construct(protected MpesaService $mpesaService)
    {
        $this->middleware('auth:sanctum');
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/bookings
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
            'payout_status'   => Booking::PAYOUT_PENDING,
        ]);

        $booking->load([
            'client:id,name,user_image,phoneNumber',
            'photographer:id,name,user_image,phoneNumber',
            'photographer.photographerProfile:user_id,hourly_rate,location',
        ]);

        return response()->json([
            'message' => 'Booking request sent.',
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
            $bookings = Booking::where('client_id', $user->id)
                ->with([
                    'photographer:id,name,user_image,phoneNumber',
                    'photographer.photographerProfile:user_id,hourly_rate,location',
                ])
                ->orderBy('booking_date', 'desc')
                ->get();
        } elseif ($user->role_id === 2) {
            $bookings = Booking::where('photographer_id', $user->id)
                ->with(['client:id,name,user_image,phoneNumber'])
                ->orderBy('booking_date', 'desc')
                ->get();
        } else {
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

        // Client: can only cancel pending bookings
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
                    return response()->json(['message' => 'Booking must be confirmed first.'], 422);
                }
                if (!$booking->isPaid()) {
                    return response()->json([
                        'message' => 'Client must pay before the booking can be completed.',
                    ], 422);
                }
                // Mark complete AND trigger payout automatically
                $booking->update(['status' => 'completed']);
                $this->triggerPayout($booking);
                $booking->load(['client:id,name', 'photographer:id,name']);
                return response()->json([
                    'message' => 'Booking completed! Payout to photographer has been initiated.',
                    'booking' => $booking,
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
    // POST /api/bookings/{id}/pay — client pays via M-Pesa
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
            return response()->json(['message' => 'Booking must be confirmed before payment.'], 422);
        }
        if ($booking->isPaid()) {
            return response()->json(['message' => 'This booking is already paid.'], 409);
        }

        $totalAmount = (int) $request->amount;

        $stkResult = $this->mpesaService->stkPush(
            phone:       $request->phone,
            amount:      $totalAmount,
            reference:   'PIXXGRAM-BK-' . $booking->id,
            description: 'Pixxgram Booking Payment',
        );

        if (!$stkResult['success']) {
            return response()->json(['message' => $stkResult['message']], 422);
        }

        $booking->update([
            'amount'                    => $totalAmount,
            'payment_status'            => Booking::PAYMENT_PENDING_PAYMENT,
            'mpesa_checkout_request_id' => $stkResult['checkout_request_id'],
        ]);

        // Pre-calculate what the split will be
        $commission = round($totalAmount * Booking::COMMISSION_RATE, 2);
        $payout     = round($totalAmount - $commission, 2);

        return response()->json([
            'message'              => 'M-Pesa prompt sent. Enter your PIN to complete payment.',
            'checkout_request_id'  => $stkResult['checkout_request_id'],
            'amount_breakdown' => [
                'total'               => $totalAmount,
                'platform_commission' => $commission,  // 10%
                'photographer_payout' => $payout,      // 90%
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/bookings/{id}/payment-status
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
            'payment_status'      => $booking->payment_status,
            'payout_status'       => $booking->payout_status,
            'mpesa_receipt'       => $booking->mpesa_receipt,
            'amount'              => $booking->amount,
            'platform_commission' => $booking->platform_commission,
            'photographer_payout' => $booking->photographer_payout,
            'paid_at'             => $booking->paid_at,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/bookings/mpesa/callback — Safaricom STK callback
    // ─────────────────────────────────────────────────────────────────
    public function mpesaCallback(Request $request)
    {
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
        $amount  = (float) ($meta->get('Amount') ?? $booking->amount);

        // Mark as paid and calculate 90/10 split
        $booking->update([
            'payment_status' => Booking::PAYMENT_PAID,
            'mpesa_receipt'  => $receipt,
            'amount'         => $amount,
            'paid_at'        => now(),
        ]);
        $booking->calculateCommission($amount);

        Log::info("Booking #{$booking->id} paid. Total: {$amount}. " .
            "Commission: {$booking->platform_commission}. " .
            "Photographer payout: {$booking->photographer_payout}");
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/bookings/payout/callback — B2C result callback
    // ─────────────────────────────────────────────────────────────────
    public function payoutCallback(Request $request)
    {
        response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted'])->send();

        $result = $request->input('Result');
        if (!$result) return;

        $reference  = $result['ReferenceData']['ReferenceItem']['Value'] ?? '';
        $resultCode = $result['ResultCode'] ?? 1;

        // Extract booking ID from reference "PAYOUT-BK-{id}"
        $bookingId = (int) str_replace('PAYOUT-BK-', '', $reference);
        $booking   = Booking::find($bookingId);
        if (!$booking) return;

        if ($resultCode === 0) {
            $params  = collect($result['ResultParameters']['ResultParameter'] ?? [])->pluck('Value', 'Key');
            $receipt = $params->get('TransactionReceipt') ?? $params->get('TransactionID');

            $booking->update([
                'payout_status'  => Booking::PAYOUT_PAID,
                'payout_receipt' => $receipt,
                'payout_at'      => now(),
            ]);
            Log::info("Payout for booking #{$bookingId} confirmed. Receipt: {$receipt}");
        } else {
            $booking->update(['payout_status' => Booking::PAYOUT_FAILED]);
            Log::warning("Payout for booking #{$bookingId} failed. Code: {$resultCode}");
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/bookings/payout/timeout — B2C timeout callback
    // ─────────────────────────────────────────────────────────────────
    public function payoutTimeout(Request $request)
    {
        response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted'])->send();
        Log::warning('B2C payout timed out: ' . json_encode($request->all()));
    }

    // ─────────────────────────────────────────────────────────────────
    // Private: trigger B2C payout to photographer after booking complete
    // ─────────────────────────────────────────────────────────────────
    private function triggerPayout(Booking $booking): void
    {
        if (!$booking->isPaid()) return;
        if ($booking->payout_status === Booking::PAYOUT_PAID) return;

        $photographer = $booking->photographer;
        $phone        = $photographer?->phoneNumber;
        $payoutAmount = $booking->photographer_payout ?? round($booking->amount * 0.9, 2);

        if (!$phone || !$payoutAmount) {
            Log::warning("Cannot payout booking #{$booking->id}: missing phone or amount.");
            $booking->update(['payout_status' => Booking::PAYOUT_FAILED]);
            return;
        }

        $booking->update(['payout_status' => Booking::PAYOUT_PROCESSING]);

        $result = $this->mpesaService->b2cPayout(
            phone:     $phone,
            amount:    $payoutAmount,
            reference: 'PAYOUT-BK-' . $booking->id,
            remarks:   "Pixxgram booking #{$booking->id} payout",
        );

        if (!$result['success']) {
            Log::error("B2C payout failed for booking #{$booking->id}: " . $result['message']);
            $booking->update([
                'payout_status'    => Booking::PAYOUT_FAILED,
                'payout_reference' => 'FAILED: ' . $result['message'],
            ]);
        } else {
            $booking->update([
                'payout_reference' => $result['conversation_id'] ?? null,
            ]);
            Log::info("B2C payout initiated for booking #{$booking->id}. Amount: {$payoutAmount}");
        }
    }
}
