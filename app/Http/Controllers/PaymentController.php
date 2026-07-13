<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    private $baseUrl = 'https://api.paystack.co';

    private function getSecretKey()
    {
        $setting = Setting::where('key', 'paystack_secret_key')->first();
        return $setting ? $setting->value : env('PAYSTACK_SECRET_KEY');
    }

    public function initialize(Request $request, $orderId)
    {
        $order = $request->user()->orders()->findOrFail($orderId);

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'Order is already paid.'], 400);
        }

        $secretKey = $this->getSecretKey();

        if (!$secretKey) {
            return response()->json(['message' => 'Payment gateway not configured.'], 500);
        }

        // Initialize Paystack transaction
        // Paystack amount is in kobo (multiply by 100)
        $initResponse = Http::withToken($secretKey)->post($this->baseUrl . '/transaction/initialize', [
            'amount' => $order->total * 100,
            'email' => $request->user()->email,
            'reference' => $order->reference,
            'callback_url' => env('APP_URL') . '/payment-success',
            'metadata' => [
                'order_id' => $order->id,
                'user_id' => $request->user()->id,
            ]
        ]);

        if (!$initResponse->successful()) {
            Log::error('Paystack Initialization Failed', ['response' => $initResponse->json()]);
            return response()->json(['message' => 'Failed to initialize payment.'], 500);
        }

        return response()->json([
            'checkoutUrl' => $initResponse->json()['data']['authorization_url'],
            'accessCode' => $initResponse->json()['data']['access_code'],
            'transactionReference' => $initResponse->json()['data']['reference']
        ]);
    }

    public function verify(Request $request, $reference)
    {
        $secretKey = $this->getSecretKey();

        if (!$secretKey) {
            return response()->json(['message' => 'Payment gateway not configured.'], 500);
        }

        $verifyResponse = Http::withToken($secretKey)->get($this->baseUrl . '/transaction/verify/' . $reference);

        if (!$verifyResponse->successful()) {
            return response()->json(['message' => 'Verification request failed.'], 500);
        }

        $data = $verifyResponse->json()['data'];

        if ($data['status'] === 'success') {
            $this->processSuccessfulPayment($data);
            return response()->json(['message' => 'Payment verified successfully.', 'status' => 'success']);
        }

        return response()->json(['message' => 'Payment not successful.', 'status' => $data['status']], 400);
    }

    public function webhook(Request $request)
    {
        $secretKey = $this->getSecretKey();
        
        // Only a post with paystack signature header gets our attention
        if ((!$request->hasHeader('x-paystack-signature'))) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        // Validate event
        if ($signature !== hash_hmac('sha512', $payload, $secretKey)) {
            Log::warning('Invalid Paystack webhook signature.');
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $event = json_decode($payload, true);

        if ($event['event'] === 'charge.success') {
            $this->processSuccessfulPayment($event['data']);
        }

        return response()->json(['message' => 'Webhook received']);
    }

    private function processSuccessfulPayment($data)
    {
        $order = Order::where('reference', $data['reference'])->first();
        
        if ($order && $order->payment_status !== 'paid') {
            $order->update(['payment_status' => 'paid', 'status' => 'processing']);
            
            // Record payment
            Payment::create([
                'order_id' => $order->id,
                'monnify_reference' => null,
                'transaction_reference' => $data['reference'],
                // Paystack amount is in kobo, convert back to NGN
                'amount' => $data['amount'] / 100,
                'status' => 'paid',
                'payment_method' => $data['channel'] ?? 'card',
                'raw_response' => json_encode($data)
            ]);
        }
    }
}
