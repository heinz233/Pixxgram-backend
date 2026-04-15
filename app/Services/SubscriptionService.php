<?php

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    // -----------------------------------------------------------------
    // Available plans (amount in KES)
    // -----------------------------------------------------------------

    public const PLANS = [
        'monthly'   => ['label' => 'Monthly',   'amount' => 1500,  'days' => 30],
        'quarterly' => ['label' => 'Quarterly',  'amount' => 3999,  'days' => 90],
        'annual'    => ['label' => 'Annual',     'amount' => 14000, 'days' => 365],
    ];

    // -----------------------------------------------------------------
    // Create a pending subscription record before payment
    // -----------------------------------------------------------------

    public function createPending(int $photographerId, string $plan, string $paymentMethod): Subscription
    {
        $planDetails = self::PLANS[$plan];

        return Subscription::create([
            'photographer_id' => $photographerId,
            'plan'            => $plan,
            'amount'          => $planDetails['amount'],
            'status'          => 'pending',
            'payment_method'  => $paymentMethod,
            'starts_at'       => now(),
            'ends_at'         => now()->addDays($planDetails['days']),
        ]);
    }

    // -----------------------------------------------------------------
    // Called from M-Pesa callback after successful payment
    // -----------------------------------------------------------------

    public function confirmPayment(string $checkoutRequestId, ?string $mpesaReceipt): bool
    {
        $subscription = Subscription::where('transaction_reference', $checkoutRequestId)->first();

        if (!$subscription) {
            Log::warning('SubscriptionService: subscription not found', [
                'checkout_request_id' => $checkoutRequestId,
            ]);
            return false;
        }

        $subscription->update([
            'status'                => 'active',
            'mpesa_receipt'         => $mpesaReceipt,
            'transaction_reference' => $checkoutRequestId,
        ]);

        // Activate photographer account
        $photographer = $subscription->photographer;

        if ($photographer) {
            $photographer->update(['status' => 'active', 'is_active' => true]);

            $photographer->photographerProfile()->update([
                'subscription_status'   => 'active',
                'subscription_end_date' => $subscription->ends_at,
            ]);
        }

        Log::info('Subscription confirmed', ['subscription_id' => $subscription->id]);

        return true;
    }

    // -----------------------------------------------------------------
    // Cancel an active subscription
    // -----------------------------------------------------------------

    public function cancel(Subscription $subscription): void
    {
        $subscription->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
        ]);

        // Mark photographer profile subscription as cancelled
        $photographer = $subscription->photographer;
        if ($photographer?->photographerProfile) {
            $photographer->photographerProfile()->update([
                'subscription_status' => 'cancelled',
            ]);
        }
    }
}
