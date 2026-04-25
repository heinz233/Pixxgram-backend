<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MpesaService
{
    private string $consumerKey;
    private string $consumerSecret;
    private string $businessShortCode;
    private string $passkey;
    private string $environment;
    private string $baseUrl;
    private string $b2cShortCode;
    private string $b2cSecurityCredential;
    private string $b2cInitiatorName;

    public function __construct()
    {
        $this->consumerKey           = config('services.mpesa.consumer_key');
        $this->consumerSecret        = config('services.mpesa.consumer_secret');
        $this->businessShortCode     = config('services.mpesa.business_shortcode');
        $this->passkey               = config('services.mpesa.passkey');
        $this->environment           = config('services.mpesa.environment', 'sandbox');
        $this->b2cShortCode          = config('services.mpesa.b2c_shortcode', config('services.mpesa.business_shortcode'));
        $this->b2cSecurityCredential = config('services.mpesa.b2c_security_credential', '');
        $this->b2cInitiatorName      = config('services.mpesa.b2c_initiator_name', 'testapi');
        $this->baseUrl               = $this->environment === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    // ─────────────────────────────────────────────────────────────────
    // STK Push — client pays platform
    // ─────────────────────────────────────────────────────────────────
    public function stkPush(string $phone, float $amount, string $reference, string $description): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Could not obtain M-Pesa access token.'];
        }

        $phone     = $this->formatPhone($phone);
        $timestamp = now()->format('YmdHis');
        $password  = base64_encode($this->businessShortCode . $this->passkey . $timestamp);

        try {
            $response = Http::withToken($token)
                ->timeout(30)
                ->post("{$this->baseUrl}/mpesa/stkpush/v1/processrequest", [
                    'BusinessShortCode' => $this->businessShortCode,
                    'Password'          => $password,
                    'Timestamp'         => $timestamp,
                    'TransactionType'   => 'CustomerPayBillOnline',
                    'Amount'            => (int) ceil($amount),
                    'PartyA'            => $phone,
                    'PartyB'            => $this->businessShortCode,
                    'PhoneNumber'       => $phone,
                    'CallBackURL'       => route('bookings.mpesa.callback'),
                    'AccountReference'  => $reference,
                    'TransactionDesc'   => $description,
                ]);

            $body = $response->json();

            if (($body['ResponseCode'] ?? '') === '0') {
                return [
                    'success'              => true,
                    'checkout_request_id'  => $body['CheckoutRequestID'],
                    'message'              => $body['CustomerMessage'] ?? 'STK push sent.',
                ];
            }

            return [
                'success' => false,
                'message' => $body['errorMessage'] ?? $body['ResponseDescription'] ?? 'STK push failed.',
            ];

        } catch (\Throwable $e) {
            Log::error('M-Pesa STK push error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // B2C Payment — platform pays photographer (90% of booking amount)
    // Uses M-Pesa Business to Customer API
    // ─────────────────────────────────────────────────────────────────
    public function b2cPayout(
        string $phone,
        float  $amount,
        string $reference,
        string $remarks = 'Pixxgram Booking Payout'
    ): array {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Could not obtain M-Pesa access token.'];
        }

        if (empty($this->b2cSecurityCredential)) {
            return [
                'success' => false,
                'message' => 'B2C security credential not configured. Add MPESA_B2C_SECURITY_CREDENTIAL to .env',
            ];
        }

        $phone = $this->formatPhone($phone);

        try {
            $response = Http::withToken($token)
                ->timeout(30)
                ->post("{$this->baseUrl}/mpesa/b2c/v1/paymentrequest", [
                    'InitiatorName'      => $this->b2cInitiatorName,
                    'SecurityCredential' => $this->b2cSecurityCredential,
                    'CommandID'          => 'BusinessPayment',
                    'Amount'             => (int) floor($amount),
                    'PartyA'             => $this->b2cShortCode,
                    'PartyB'             => $phone,
                    'Remarks'            => $remarks,
                    'QueueTimeOutURL'    => route('bookings.payout.timeout'),
                    'ResultURL'          => route('bookings.payout.callback'),
                    'Occasion'           => $reference,
                ]);

            $body = $response->json();

            if (($body['ResponseCode'] ?? '') === '0') {
                return [
                    'success'           => true,
                    'conversation_id'   => $body['ConversationID'],
                    'originator_id'     => $body['OriginatorConversationID'],
                    'message'           => $body['ResponseDescription'] ?? 'Payout initiated.',
                ];
            }

            return [
                'success' => false,
                'message' => $body['errorMessage'] ?? $body['ResponseDescription'] ?? 'B2C payout failed.',
            ];

        } catch (\Throwable $e) {
            Log::error('M-Pesa B2C error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Get OAuth access token
    // ─────────────────────────────────────────────────────────────────
    public function getAccessToken(): ?string
    {
        return Cache::remember('mpesa_access_token', 3500, function () {
            try {
                $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
                    ->timeout(15)
                    ->get("{$this->baseUrl}/oauth/v1/generate?grant_type=client_credentials");

                return $response->json()['access_token'] ?? null;

            } catch (\Throwable $e) {
                Log::error('M-Pesa token error: ' . $e->getMessage());
                return null;
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // Format phone to 254XXXXXXXXX
    // ─────────────────────────────────────────────────────────────────
    public function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0'))   return '254' . substr($phone, 1);
        if (str_starts_with($phone, '+'))   return substr($phone, 1);
        if (!str_starts_with($phone, '254')) return '254' . $phone;
        return $phone;
    }
}
