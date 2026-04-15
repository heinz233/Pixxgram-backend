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

    public function __construct()
    {
        $this->consumerKey       = config('services.mpesa.consumer_key');
        $this->consumerSecret    = config('services.mpesa.consumer_secret');
        $this->businessShortCode = config('services.mpesa.business_shortcode');
        $this->passkey           = config('services.mpesa.passkey');
        $this->environment       = config('services.mpesa.environment', 'sandbox');
        $this->baseUrl           = $this->environment === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    // -----------------------------------------------------------------
    // STK Push (Lipa Na M-Pesa Online)
    // -----------------------------------------------------------------

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
                    'CallBackURL'       => route('subscriptions.mpesa.callback'),
                    'AccountReference'  => $reference,
                    'TransactionDesc'   => $description,
                ]);

            $data = $response->json();

            if ($response->successful() && isset($data['CheckoutRequestID'])) {
                return [
                    'success'              => true,
                    'checkout_request_id'  => $data['CheckoutRequestID'],
                    'merchant_request_id'  => $data['MerchantRequestID'],
                ];
            }

            Log::error('M-Pesa STK Push failed', ['response' => $data]);

            return [
                'success' => false,
                'message' => $data['errorMessage'] ?? 'STK Push failed.',
            ];

        } catch (\Exception $e) {
            Log::error('M-Pesa STK Push exception', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'M-Pesa service unavailable.'];
        }
    }

    // -----------------------------------------------------------------
    // Access token (cached for 50 minutes — token expires in 60)
    // -----------------------------------------------------------------

    public function getAccessToken(): ?string
    {
        return Cache::remember('mpesa_access_token', 50 * 60, function () {
            try {
                $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
                    ->timeout(15)
                    ->get("{$this->baseUrl}/oauth/v1/generate?grant_type=client_credentials");

                if ($response->successful()) {
                    return $response->json('access_token');
                }

                Log::error('M-Pesa token generation failed', ['body' => $response->body()]);
                return null;

            } catch (\Exception $e) {
                Log::error('M-Pesa token exception', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }

        if (str_starts_with($phone, '+')) {
            return substr($phone, 1);
        }

        return $phone;
    }
}
