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
        $this->middleware('auth:sanctum')->except(['plans', 'mpesaCallback']);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/subscriptions/plans  — public
    // ─────────────────────────────────────────────────────────────────
    public function plans()
    {
        return response()->json([
            'plans' => SubscriptionService::PLANS,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/subscriptions/current
    //
    // Strategy:
    //   1. Try to find an active subscription row in the subscriptions table
    //   2. If none found, check photographer_profile.subscription_status as fallback
    //      (handles cases where subscription was activated manually or via admin)
    //   3. If profile says active but no subscription row, create a synthetic response
    // ─────────────────────────────────────────────────────────────────
    public function current()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        // ── Step 1: Try the subscriptions table ──────────────────────
        $subscription = Subscription::where('photographer_id', $user->id)
            ->where('status', 'active')
            ->where(function ($q) {
                // ends_at in the future, OR ends_at is null (manually activated)
                $q->where('ends_at', '>=', now())
                  ->orWhereNull('ends_at');
            })
            ->latest()
            ->first();

        if ($subscription) {
            return response()->json([
                'subscription'   => $subscription,
                'days_remaining' => $subscription->days_remaining,
            ]);
        }

        // ── Step 2: Check if photographer_profile has active subscription ──
        $profile = $user->photographerProfile;

        if ($profile &&
            $profile->subscription_status === 'active' &&
            $profile->subscription_end_date &&
            $profile->subscription_end_date->isFuture()
        ) {
            // Profile is active but no matching subscriptions row
            // This happens when activated via phpMyAdmin SQL or admin panel
            // Sync by finding any subscription row (any status) and activating it,
            // or build a synthetic response from the profile data

            // Try to find ANY subscription row for this photographer to update
            $anyRow = Subscription::where('photographer_id', $user->id)
                ->latest()
                ->first();

            if ($anyRow) {
                // Activate the most recent row to match the profile
                $anyRow->update([
                    'status'  => 'active',
                    'ends_at' => $profile->subscription_end_date,
                ]);

                return response()->json([
                    'subscription'   => $anyRow->fresh(),
                    'days_remaining' => max(0, (int) now()->diffInDays($profile->subscription_end_date)),
                ]);
            }

            // No subscription row at all — return synthetic data from profile
            return response()->json([
                'subscription' => [
                    'id'           => null,
                    'plan'         => 'manual',
                    'amount'       => 0,
                    'status'       => 'active',
                    'ends_at'      => $profile->subscription_end_date,
                    'starts_at'    => now(),
                    'created_at'   => now(),
                ],
                'days_remaining' => max(0, (int) now()->diffInDays($profile->subscription_end_date)),
            ]);
        }

        // ── Step 3: Truly no subscription ────────────────────────────
        return response()->json([
            'subscription'   => null,
            'days_remaining' => 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/subscriptions/history
    // ─────────────────────────────────────────────────────────────────
    public function history()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $subscriptions = Subscription::where('photographer_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['subscriptions' => $subscriptions]);
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/subscriptions/subscribe
    // ─────────────────────────────────────────────────────────────────
    public function subscribe(Request $request)
    {
        $request->validate([
            'plan'           => 'required|in:' . implode(',', array_keys(SubscriptionService::PLANS)),
            'payment_method' => 'required|in:mpesa,card,paypal',
            'phone'          => 'required_if:payment_method,mpesa|nullable|string',
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!$user->isPhotographer()) {
            return response()->json(['error' => 'Only photographers can subscribe.'], 403);
        }

        // Check for existing active subscription
        $existing = Subscription::where('photographer_id', $user->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('ends_at', '>=', now())->orWhereNull('ends_at');
            })
            ->exists();

        // Also check profile
        $profileActive = $user->photographerProfile?->subscription_status === 'active' &&
            $user->photographerProfile?->subscription_end_date?->isFuture();

        if ($existing || $profileActive) {
            return response()->json([
                'error' => 'You already have an active subscription. Cancel it first to change plans.',
            ], 409);
        }

        $subscription = $this->subscriptionService->createPending(
            $user->id,
            $request->plan,
            $request->payment_method
        );

        if ($request->payment_method === 'mpesa') {
            $stkResult = $this->mpesaService->stkPush(
                phone:       $request->phone,
                amount:      $subscription->amount,
                reference:   'PIXXGRAM-SUB-' . $subscription->id,
                description: 'Pixxgram ' . ucfirst($request->plan) . ' Subscription',
            );

            if (!$stkResult['success']) {
                $subscription->update(['status' => 'failed']);
                return response()->json(['error' => $stkResult['message']], 422);
            }

            return response()->json([
                'message'             => 'M-Pesa prompt sent. Enter your PIN to complete payment.',
                'subscription_id'     => $subscription->id,
                'checkout_request_id' => $stkResult['checkout_request_id'],
            ]);
        }

        return response()->json([
            'message'         => 'Subscription initiated.',
            'subscription_id' => $subscription->id,
            'amount'          => $subscription->amount,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/subscriptions/{id}/cancel
    // ─────────────────────────────────────────────────────────────────
    public function cancel($id)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $subscription = Subscription::where('id', $id)
            ->where('photographer_id', $user->id)
            ->firstOrFail();

        if (in_array($subscription->status, ['cancelled', 'expired'])) {
            return response()->json([
                'error' => 'Subscription is already ' . $subscription->status . '.',
            ], 409);
        }

        $subscription->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
        ]);

        // Also update photographer profile
        if ($user->photographerProfile) {
            $user->photographerProfile()->update([
                'subscription_status' => 'cancelled',
            ]);
        }

        return response()->json(['message' => 'Subscription cancelled successfully.']);
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/subscriptions/mpesa/callback  — Safaricom webhook
    // ─────────────────────────────────────────────────────────────────
    public function mpesaCallback(Request $request)
    {
        response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted'])->send();

        $body = $request->input('Body.stkCallback');
        if (!$body) return;

        $checkoutRequestId = $body['CheckoutRequestID'] ?? null;
        $resultCode        = $body['ResultCode'] ?? 1;

        if ($resultCode !== 0) {
            Subscription::where('transaction_reference', $checkoutRequestId)
                ->update(['status' => 'failed']);
            return;
        }

        $meta    = collect($body['CallbackMetadata']['Item'] ?? [])->pluck('Value', 'Name');
        $receipt = $meta->get('MpesaReceiptNumber');

        $this->subscriptionService->confirmPayment($checkoutRequestId, $receipt);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/subscriptions/mpesa/status/{checkoutRequestId}
    // ─────────────────────────────────────────────────────────────────
    public function mpesaStatus($checkoutRequestId)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $subscription = Subscription::where('transaction_reference', $checkoutRequestId)
            ->where('photographer_id', $user->id)
            ->firstOrFail();

        return response()->json([
            'status'        => $subscription->status,
            'mpesa_receipt' => $subscription->mpesa_receipt,
            'paid_at'       => $subscription->updated_at,
        ]);
    }
}