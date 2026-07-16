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

        // Generate a fresh unique reference per attempt to avoid Paystack "duplicate receipt" errors on retries
        $attemptRef = $order->reference . '-' . now()->timestamp . '-' . \Illuminate\Support\Str::random(6);

        // Initialize Paystack transaction
        // Paystack amount is in kobo (multiply by 100)
        $initResponse = Http::withToken($secretKey)->post($this->baseUrl . '/transaction/initialize', [
            'amount' => (int) round($order->total * 100),
            'email' => $request->user()->email,
            'reference' => $attemptRef,
            'callback_url' => env('APP_URL') . '/payment-success',
            'metadata' => [
                'order_id' => $order->id,
                'order_reference' => $order->reference,
                'user_id' => $request->user()->id,
                'custom_fields' => [
                    [
                        'display_name' => 'Customer Name',
                        'variable_name' => 'customer_name',
                        'value' => $order->customer_name
                    ],
                    [
                        'display_name' => 'Order Number',
                        'variable_name' => 'order_number',
                        'value' => $order->reference
                    ]
                ]
            ]
        ]);

        if (!$initResponse->successful()) {
            $errorMsg = $initResponse->json()['message'] ?? 'Failed to initialize payment.';
            Log::error('Paystack Initialization Failed', ['response' => $initResponse->json()]);
            return response()->json(['message' => 'Payment Error: ' . $errorMsg], 500);
        }

        return response()->json([
            'checkoutUrl'          => $initResponse->json()['data']['authorization_url'],
            'accessCode'           => $initResponse->json()['data']['access_code'],
            'transactionReference' => $initResponse->json()['data']['reference'],
            'amountKobo'           => (int) round($order->total * 100),
        ]);
    }

    /**
     * Admin endpoint: re-verify ALL non-paid orders against Paystack and back-fill their status.
     */
    public function adminReverifyAll(Request $request)
    {
        $secretKey = $this->getSecretKey();

        if (!$secretKey) {
            return response()->json(['message' => 'Payment gateway not configured.'], 500);
        }

        $orders = Order::whereNotIn('payment_status', ['paid'])->latest()->limit(100)->get();

        $updated = [];

        foreach ($orders as $order) {
            // Query Paystack for transactions matching this order reference
            $response = Http::withToken($secretKey)
                ->get($this->baseUrl . '/transaction', [
                    'perPage'   => 20,
                    'page'      => 1,
                    'reference' => $order->reference,
                ]);

            if (!$response->successful()) {
                continue;
            }

            $transactions = $response->json()['data'] ?? [];

            foreach ($transactions as $txn) {
                if ($txn['status'] === 'success') {
                    $order->update(['payment_status' => 'paid', 'status' => 'paid']);

                    $exists = Payment::where('transaction_reference', $txn['reference'])->exists();
                    if (!$exists) {
                        Payment::create([
                            'order_id'              => $order->id,
                            'monnify_reference'     => null,
                            'transaction_reference' => $txn['reference'],
                            'amount'                => $txn['amount'] / 100,
                            'status'                => 'paid',
                            'payment_method'        => $txn['channel'] ?? 'card',
                            'raw_response'          => json_encode($txn),
                        ]);
                    }

                    $updated[] = $order->reference;
                    break;
                }
            }
        }

        return response()->json([
            'message'         => count($updated) . ' order(s) updated to paid.',
            'updated_orders'  => $updated,
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

        // Handle failed/cancelled
        $this->processFailedPayment($data, $data['status']);

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
        } else if ($event['event'] === 'charge.failed') {
            $this->processFailedPayment($event['data'], 'failed');
        }

        return response()->json(['message' => 'Webhook received']);
    }

    private function processSuccessfulPayment($data)
    {
        // Use metadata.order_reference if set (new format)
        $orderRef = null;
        $metadata = $data['metadata'] ?? null;
        
        // Sometimes Paystack returns metadata as a JSON string
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        if (is_array($metadata) && isset($metadata['order_reference'])) {
            $orderRef = $metadata['order_reference'];
        }
        
        // If not found in metadata, try extracting from the attempt reference (e.g. ORD-XXXX-time-rand)
        if (!$orderRef && isset($data['reference'])) {
            $parts = explode('-', $data['reference']);
            if (count($parts) >= 2) {
                $orderRef = $parts[0] . '-' . $parts[1];
            } else {
                $orderRef = $data['reference'];
            }
        }

        $order = Order::where('reference', $orderRef)->first();
        
        // Also try direct match as fallback (for old orders)
        if (!$order) {
            $order = Order::where('reference', $data['reference'])->first();
        }
        
        if ($order && $order->payment_status !== 'paid') {
            $order->update(['payment_status' => 'paid', 'status' => 'paid']);
            
            // Record payment
            Payment::create([
                'order_id'              => $order->id,
                'monnify_reference'     => null,
                'transaction_reference' => $data['reference'],
                // Paystack amount is in kobo, convert back to NGN
                'amount'                => $data['amount'] / 100,
                'status'                => 'paid',
                'payment_method'        => $data['channel'] ?? 'card',
                'raw_response'          => json_encode($data)
            ]);

            // Send payment confirmation emails
            try {
                $order->load('user', 'items.product');
                if ($order->user) {
                    (new \App\Notifications\OrderStatusUpdate($order))->send($order->user);
                }
                
                $adminUser = (object)['email' => 'mannabridalsupport@gmail.com', 'name' => 'Admin'];
                (new \App\Notifications\OrderPlacedAdminNotification($order))->send($adminUser);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Payment confirmation email failed: ' . $e->getMessage());
            }
        } else if (!$order) {
            Log::error('Paystack webhook/verify order not found for reference: ' . $data['reference']);
        }
    }

    private function processFailedPayment($data, $statusStr)
    {
        $orderRef = null;
        $metadata = $data['metadata'] ?? null;

        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        if (is_array($metadata) && isset($metadata['order_reference'])) {
            $orderRef = $metadata['order_reference'];
        }
        
        if (!$orderRef && isset($data['reference'])) {
            $parts = explode('-', $data['reference']);
            if (count($parts) >= 2) {
                $orderRef = $parts[0] . '-' . $parts[1];
            } else {
                $orderRef = $data['reference'];
            }
        }

        $order = Order::where('reference', $orderRef)->first();
        if (!$order) {
            $order = Order::where('reference', $data['reference'])->first();
        }

        if ($order && $order->payment_status !== 'paid') {
            $newStatus = strtolower($statusStr);
            if ($newStatus === 'abandoned') {
                $newStatus = 'cancelled';
            }
            
            // Limit to allowed enum values or set as failed
            if (!in_array($newStatus, ['failed', 'cancelled'])) {
                $newStatus = 'failed';
            }

            $order->update(['payment_status' => $newStatus, 'status' => $newStatus]);
            
            Payment::create([
                'order_id'              => $order->id,
                'monnify_reference'     => null,
                'transaction_reference' => $data['reference'],
                'amount'                => ($data['amount'] ?? 0) / 100,
                'status'                => $newStatus,
                'payment_method'        => $data['channel'] ?? 'card',
                'raw_response'          => json_encode($data)
            ]);

            // Send payment failed notification
            try {
                $order->load('user');
                if ($order->user) {
                    (new \App\Notifications\OrderStatusUpdate($order))->send($order->user);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Payment failed email failed: ' . $e->getMessage());
            }
        }
    }
}
