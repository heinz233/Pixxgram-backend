<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    // ─────────────────────────────────────────────────────────────────
    // Plans available on the platform (amount in KES)
    // ─────────────────────────────────────────────────────────────────
    public const PLANS = [
        'monthly' => [
            'label'  => 'Monthly',
            'amount' => 500,
            'days'   => 30,
        ],
        'quarterly' => [
            'label'  => 'Quarterly',
            'amount' => 1300,
            'days'   => 90,
        ],
        'annual' => [
            'label'  => 'Annual',
            'amount' => 5500,
            'days'   => 365,
        ],
    ];

    // ─────────────────────────────────────────────────────────────────
    // Create a pending subscription record before payment is confirmed
    // ─────────────────────────────────────────────────────────────────
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

    // ─────────────────────────────────────────────────────────────────
    // Called after M-Pesa callback confirms successful payment
    // ─────────────────────────────────────────────────────────────────
    public function confirmPayment(string $checkoutRequestId, ?string $mpesaReceipt): bool
    {
        $subscription = Subscription::where('transaction_reference', $checkoutRequestId)->first();

        if (!$subscription) {
            Log::warning('SubscriptionService::confirmPayment — subscription not found', [
                'checkout_request_id' => $checkoutRequestId,
            ]);
            return false;
        }

        $subscription->update([
            'status'        => 'active',
            'mpesa_receipt' => $mpesaReceipt,
        ]);

        // Activate the photographer account
        $photographer = $subscription->photographer;

        if ($photographer) {
            $photographer->update([
                'status'    => 'active',
                'is_active' => true,
            ]);

            $photographer->photographerProfile()->updateOrCreate(
                ['user_id' => $photographer->id],
                [
                    'subscription_status'   => 'active',
                    'subscription_end_date' => $subscription->ends_at,
                ]
            );
        }

        Log::info('Subscription confirmed', [
            'subscription_id' => $subscription->id,
            'photographer_id' => $subscription->photographer_id,
            'plan'            => $subscription->plan,
        ]);

        return true;
    }

    // ─────────────────────────────────────────────────────────────────
    // Cancel a subscription
    // ─────────────────────────────────────────────────────────────────
    public function cancel(Subscription $subscription): void
    {
        $subscription->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $photographer = $subscription->photographer;
        if ($photographer?->photographerProfile) {
            $photographer->photographerProfile()->update([
                'subscription_status' => 'cancelled',
            ]);
        }
    }
}