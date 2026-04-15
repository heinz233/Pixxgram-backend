<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptionService,
        protected MpesaService $mpesaService,
    ) {
        $this->middleware('auth:api');
    }

    // -----------------------------------------------------------------
    // GET /subscriptions/plans — public plan listing
    // -----------------------------------------------------------------
    public function plans()
    {
        return response()->json([
            'plans' => SubscriptionService::PLANS,
        ]);
    }

    // -----------------------------------------------------------------
    // GET /subscriptions/current — authenticated photographer's active sub
    // -----------------------------------------------------------------
    public function current()
    {
        $subscription = Subscription::where('photographer_id', Auth::id())
            ->active()
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription found'], 404);
        }

        return response()->json([
            'subscription'  => $subscription,
            'days_remaining' => $subscription->days_remaining,
        ]);
    }

    // -----------------------------------------------------------------
    // GET /subscriptions/history — all subs for the authenticated user
    // -----------------------------------------------------------------
    public function history()
    {
        $subscriptions = Subscription::where('photographer_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['subscriptions' => $subscriptions]);
    }

    // -----------------------------------------------------------------
    // POST /subscriptions/subscribe — initiate a new subscription
    // -----------------------------------------------------------------
    public function subscribe(Request $request)
    {
        $request->validate([
            'plan'           => 'required|in:' . implode(',', array_keys(SubscriptionService::PLANS)),
            'payment_method' => 'required|in:mpesa,card,paypal',
            'phone'          => 'required_if:payment_method,mpesa|string|nullable',
        ]);

        $photographer = Auth::user();

        if ($photographer->role !== 'photographer') {
            return response()->json(['error' => 'Only photographers can subscribe'], 403);
        }

        // Prevent duplicate active subscriptions
        if (Subscription::where('photographer_id', $photographer->id)->active()->exists()) {
            return response()->json(['error' => 'You already have an active subscription'], 409);
        }

        $subscription = $this->subscriptionService->createPending($photographer->id, $request->plan, $request->payment_method);

        // Kick off M-Pesa STK push
        if ($request->payment_method === 'mpesa') {
            $stkResult = $this->mpesaService->stkPush(
                phone: $request->phone,
                amount: $subscription->amount,
                reference: 'PIXXGRAM-SUB-' . $subscription->id,
                description: 'Pixxgram ' . ucfirst($request->plan) . ' Subscription',
            );

            if (!$stkResult['success']) {
                $subscription->update(['status' => 'failed']);
                return response()->json(['error' => $stkResult['message']], 422);
            }

            return response()->json([
                'message'            => 'STK Push sent. Enter your M-Pesa PIN to complete.',
                'subscription_id'    => $subscription->id,
                'checkout_request_id' => $stkResult['checkout_request_id'],
            ]);
        }

        // For card / PayPal return the pending subscription; front-end completes payment
        return response()->json([
            'message'         => 'Subscription initiated.',
            'subscription_id' => $subscription->id,
            'amount'          => $subscription->amount,
        ]);
    }

    // -----------------------------------------------------------------
    // POST /subscriptions/{id}/cancel — cancel own subscription
    // -----------------------------------------------------------------
    public function cancel($id)
    {
        $subscription = Subscription::where('id', $id)
            ->where('photographer_id', Auth::id())
            ->firstOrFail();

        if (in_array($subscription->status, ['cancelled', 'expired'])) {
            return response()->json(['error' => 'Subscription is already ' . $subscription->status], 409);
        }

        $subscription->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json(['message' => 'Subscription cancelled successfully']);
    }

    // -----------------------------------------------------------------
    // POST /subscriptions/mpesa/callback — Safaricom Daraja webhook
    // -----------------------------------------------------------------
    public function mpesaCallback(Request $request)
    {
        // Always respond 200 immediately so Safaricom does not retry
        response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted'])->send();

        $body = $request->input('Body.stkCallback');
        if (!$body) return;

        $checkoutRequestId = $body['CheckoutRequestID'] ?? null;
        $resultCode        = $body['ResultCode'] ?? 1;

        // Failed / cancelled by user
        if ($resultCode !== 0) {
            Subscription::where('transaction_reference', $checkoutRequestId)
                ->update(['status' => 'failed']);
            return;
        }

        // Extract M-Pesa metadata
        $meta = collect($body['CallbackMetadata']['Item'] ?? [])
            ->pluck('Value', 'Name');

        $receipt = $meta->get('MpesaReceiptNumber');

        $this->subscriptionService->confirmPayment($checkoutRequestId, $receipt);
    }

    // -----------------------------------------------------------------
    // GET /subscriptions/mpesa/status/{checkoutRequestId} — poll status
    // -----------------------------------------------------------------
    public function mpesaStatus($checkoutRequestId)
    {
        $subscription = Subscription::where('transaction_reference', $checkoutRequestId)
            ->where('photographer_id', Auth::id())
            ->firstOrFail();

        return response()->json([
            'status'      => $subscription->status,
            'mpesa_receipt' => $subscription->mpesa_receipt,
            'paid_at'     => $subscription->updated_at,
        ]);
    }
}