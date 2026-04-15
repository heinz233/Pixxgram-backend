<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    private $mpesaConsumerKey;
    private $mpesaConsumerSecret;
    private $mpesaBusinessShortCode;
    private $mpesaPasskey;
    private $mpesaEnvironment;

    public function __construct()
    {
        $this->mpesaConsumerKey = env('MPESA_CONSUMER_KEY');
        $this->mpesaConsumerSecret = env('MPESA_CONSUMER_SECRET');
        $this->mpesaBusinessShortCode = env('MPESA_BUSINESS_SHORTCODE');
        $this->mpesaPasskey = env('MPESA_PASSKEY');
        $this->mpesaEnvironment = env('MPESA_ENVIRONMENT', 'sandbox');
    }

    public function initiatePayment(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'phone' => 'required_if:payment_method,mpesa|string',
            'payment_method' => 'required|in:mpesa,card,paypal',
            'type' => 'required|in:registration,monthly'
        ]);

        $user = auth()->user();

        switch ($request->payment_method) {
            case 'mpesa':
                return $this->initiateMpesaPayment($request);
            case 'card':
                return $this->initiateCardPayment($request);
            case 'paypal':
                return $this->initiatePaypalPayment($request);
            default:
                return response()->json(['error' => 'Invalid payment method'], 400);
        }
    }

    private function initiateMpesaPayment($request)
    {
        // Format phone number (remove leading 0 or +254)
        $phone = $request->phone;
        if (substr($phone, 0, 1) === '0') {
            $phone = '254' . substr($phone, 1);
        } elseif (substr($phone, 0, 4) === '+254') {
            $phone = substr($phone, 1);
        }
        
        // Get access token
        $token = $this->getMpesaAccessToken();
        
        if (!$token) {
            Log::error('Failed to get M-Pesa access token');
            return response()->json(['error' => 'Payment service unavailable'], 500);
        }

        $timestamp = now()->format('YmdHis');
        $password = base64_encode($this->mpesaBusinessShortCode . $this->mpesaPasskey . $timestamp);

        $response = Http::withToken($token)
            ->post('https://' . ($this->mpesaEnvironment === 'sandbox' ? 'sandbox.' : '') . 'safaricom.co.ke/mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => $this->mpesaBusinessShortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => $request->amount,
                'PartyA' => $phone,
                'PartyB' => $this->mpesaBusinessShortCode,
                'PhoneNumber' => $phone,
                'CallBackURL' => route('mpesa.callback'),
                'AccountReference' => 'Pixxgram Payment',
                'TransactionDesc' => $request->type . ' fee payment'
            ]);

        $responseData = $response->json();

        if ($response->successful() && isset($responseData['CheckoutRequestID'])) {
            // Create pending subscription record
            $subscription = Subscription::create([
                'photographer_id' => auth()->id(),
                'amount' => $request->amount,
                'type' => $request->type,
                'status' => 'pending',
                'payment_method' => 'mpesa',
                'transaction_reference' => $responseData['CheckoutRequestID'],
                'callback_payload' => $responseData
            ]);

            return response()->json([
                'message' => 'STK push sent to your phone',
                'checkout_request_id' => $responseData['CheckoutRequestID']
            ]);
        }

        Log::error('M-Pesa STK Push failed', ['response' => $responseData]);
        return response()->json(['error' => 'Payment initiation failed. Please try again.'], 500);
    }

    public function mpesaCallback(Request $request)
    {
        Log::info('M-Pesa Callback Received', $request->all());
        
        $callbackData = $request->all();
        
        if (isset($callbackData['Body']['stkCallback'])) {
            $stkCallback = $callbackData['Body']['stkCallback'];
            $checkoutRequestId = $stkCallback['CheckoutRequestID'];
            $resultCode = $stkCallback['ResultCode'];
            $resultDesc = $stkCallback['ResultDesc'] ?? '';
            
            $subscription = Subscription::where('transaction_reference', $checkoutRequestId)->first();
            
            if ($subscription) {
                if ($resultCode == 0) {
                    // Payment successful
                    $callbackMetadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
                    $mpesaReceiptNumber = null;
                    $transactionDate = null;
                    
                    foreach ($callbackMetadata as $item) {
                        if ($item['Name'] === 'MpesaReceiptNumber') {
                            $mpesaReceiptNumber = $item['Value'];
                        }
                        if ($item['Name'] === 'TransactionDate') {
                            $transactionDate = $item['Value'];
                        }
                    }
                    
                    $subscription->update([
                        'status' => 'paid',
                        'callback_payload' => array_merge($callbackData, ['mpesa_receipt' => $mpesaReceiptNumber])
                    ]);
                    
                    // Update photographer profile
                    $photographer = User::find($subscription->photographer_id);
                    
                    if ($photographer) {
                        if ($subscription->type === 'registration') {
                            $photographer->update(['status' => 'active']);
                            $photographer->photographerProfile()->update([
                                'subscription_status' => 'active',
                                'subscription_end_date' => now()->addMonth()
                            ]);
                        } else if ($subscription->type === 'monthly') {
                            // Extend subscription
                            $profile = $photographer->photographerProfile;
                            $currentEndDate = $profile->subscription_end_date;
                            
                            if ($currentEndDate && $currentEndDate->isFuture()) {
                                $newEndDate = $currentEndDate->addMonth();
                            } else {
                                $newEndDate = now()->addMonth();
                            }
                            
                            $profile->update([
                                'subscription_end_date' => $newEndDate,
                                'subscription_status' => 'active'
                            ]);
                        }
                        
                        // Send confirmation notification
                        $this->sendPaymentConfirmation($photographer, $subscription);
                    }
                    
                    Log::info('Payment successful', ['subscription_id' => $subscription->id]);
                } else {
                    $subscription->update([
                        'status' => 'failed',
                        'callback_payload' => $callbackData
                    ]);
                    
                    Log::warning('Payment failed', [
                        'subscription_id' => $subscription->id,
                        'result_code' => $resultCode,
                        'result_desc' => $resultDesc
                    ]);
                }
            } else {
                Log::error('Subscription not found', ['checkout_request_id' => $checkoutRequestId]);
            }
        }
        
        return response()->json(['message' => 'Callback received successfully']);
    }

    private function getMpesaAccessToken()
    {
        try {
            $response = Http::withBasicAuth($this->mpesaConsumerKey, $this->mpesaConsumerSecret)
                ->get('https://' . ($this->mpesaEnvironment === 'sandbox' ? 'sandbox.' : '') . 'safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');

            if ($response->successful()) {
                return $response->json()['access_token'];
            }
            
            Log::error('M-Pesa token generation failed', ['response' => $response->body()]);
            return null;
        } catch (\Exception $e) {
            Log::error('M-Pesa token exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function initiateCardPayment($request)
    {
        // Implement Stripe card payment
        // This would use Laravel Cashier
        return response()->json(['message' => 'Card payment coming soon'], 501);
    }

    private function initiatePaypalPayment($request)
    {
        // Implement PayPal payment
        return response()->json(['message' => 'PayPal payment coming soon'], 501);
    }

    private function sendPaymentConfirmation($user, $subscription)
    {
        // Send email confirmation
        try {
            // You'll need to implement this with Mail or Notification
            // Mail::to($user->email)->send(new PaymentConfirmation($subscription));
            
            // Send SMS confirmation (using Africa's Talking or similar)
            // $this->sendSms($user->phone, "Payment of KSh {$subscription->amount} received. Thank you!");
        } catch (\Exception $e) {
            Log::error('Failed to send payment confirmation', ['error' => $e->getMessage()]);
        }
    }
}