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

        // Store this pending payment attempt so we can verify it later if webhook fails
        \App\Models\Payment::create([
            'order_id'              => $order->id,
            'transaction_reference' => $attemptRef,
            'amount'                => $order->total,
            'status'                => 'pending',
            'payment_method'        => 'paystack'
        ]);

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
                    $history = is_array($order->status_history) ? $order->status_history : [];
                    $history[] = [
                        'status' => 'processing',
                        'timestamp' => now()->toIso8601String()
                    ];
                    $order->update(['payment_status' => 'paid', 'status' => 'processing', 'status_history' => $history]);

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

    /**
     * Verify all pending payment attempts for a specific order.
     * This acts as a manual fallback if the webhook fails to arrive.
     */
    public function verifyOrderPayments(Request $request, $orderId)
    {
        $order = $request->user()->orders()->findOrFail($orderId);

        if ($order->payment_status === 'paid') {
            return response()->json([
                'message' => 'Order is already paid.',
                'status' => 'paid'
            ]);
        }

        $secretKey = $this->getSecretKey();

        if (!$secretKey) {
            return response()->json(['message' => 'Payment gateway not configured.'], 500);
        }

        $pendingPayments = \App\Models\Payment::where('order_id', $order->id)
            ->where('status', 'pending')
            ->whereNotNull('transaction_reference')
            ->get();

        $verified = false;

        foreach ($pendingPayments as $payment) {
            $verifyResponse = Http::withToken($secretKey)->get($this->baseUrl . '/transaction/verify/' . $payment->transaction_reference);
            
            if ($verifyResponse->successful()) {
                $data = $verifyResponse->json()['data'];
                if ($data['status'] === 'success') {
                    // This will update the order to paid and process stock
                    $this->processSuccessfulPayment($data);
                    
                    // Mark this specific payment as successful
                    $payment->update(['status' => 'paid']);
                    
                    $verified = true;
                    break;
                } else if ($data['status'] === 'failed' || $data['status'] === 'abandoned') {
                    $payment->update(['status' => 'failed']);
                }
            }
        }

        if ($verified) {
            return response()->json([
                'message' => 'Payment found and verified successfully.',
                'status' => 'paid'
            ]);
        }

        return response()->json([
            'message' => 'No successful payments found. Please try paying again.',
            'status' => 'unpaid'
        ]);
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
        } else if ($event['event'] === 'refund.processed') {
            $this->processRefundWebhook($event['data']);
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
        
        // Final fallback: Match by email, exact amount, and unpaid status
        if (!$order && isset($data['amount']) && isset($data['customer']['email'])) {
            $order = Order::where('customer_email', $data['customer']['email'])
                          ->where('payment_status', 'unpaid')
                          ->where('total', $data['amount'] / 100)
                          ->latest()
                          ->first();
        }
        
        if ($order && $order->payment_status !== 'paid') {
            $history = is_array($order->status_history) ? $order->status_history : [];
            $history[] = [
                'status' => 'processing',
                'timestamp' => now()->toIso8601String()
            ];
            $order->update(['payment_status' => 'paid', 'status' => 'processing', 'status_history' => $history]);
            
            // Auto-deduct stock
            foreach ($order->items as $item) {
                if ($item->product) {
                    $item->product->decrement('stock', $item->quantity);
                }
            }
            
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
                
                $admins = \App\Models\User::where('role', 'admin')->get();
                foreach ($admins as $adminUser) {
                    (new \App\Notifications\OrderPlacedAdminNotification($order))->send($adminUser);
                }
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

        // A failed/abandoned payment should NOT cancel the order.
        // Keep it as unpaid so the customer can retry from the Account page.
        if ($order && $order->payment_status !== 'paid') {
            Log::info('Payment failed/abandoned for order ' . ($order->reference ?? '?') . ' — keeping order unpaid for retry.', [
                'paystack_status' => $statusStr,
            ]);
            // Intentionally no status change and no notification.
        }
    }

    /**
     * Initiate a Paystack refund for a paid order.
     * Called internally from OrderController::cancelOrder().
     *
     * @param  \App\Models\Order  $order
     * @return array{success: bool, message: string}
     */
    public function refund(Order $order): array
    {
        $secretKey = $this->getSecretKey();

        if (!$secretKey) {
            Log::error('Refund failed: Paystack secret key not configured.', ['order' => $order->reference]);
            return ['success' => false, 'message' => 'Payment gateway not configured.'];
        }

        // Find the successful payment record
        $payment = Payment::where('order_id', $order->id)
            ->where('status', 'paid')
            ->latest()
            ->first();

        if (!$payment || !$payment->transaction_reference) {
            Log::error('Refund failed: No paid payment record found.', ['order' => $order->reference]);
            return ['success' => false, 'message' => 'No payment record found for this order.'];
        }

        // Call Paystack refund API
        $refundResponse = Http::withToken($secretKey)->post($this->baseUrl . '/refund', [
            'transaction' => $payment->transaction_reference,
            'amount'      => (int) round($order->total * 100), // kobo
        ]);

        if (!$refundResponse->successful() || !($refundResponse->json()['status'] ?? false)) {
            $errorMsg = $refundResponse->json()['message'] ?? 'Refund request failed.';
            Log::error('Paystack Refund Failed', [
                'order'    => $order->reference,
                'response' => $refundResponse->json(),
            ]);
            $payment->update([
                'refund_status' => 'failed',
            ]);
            return ['success' => false, 'message' => $errorMsg];
        }

        $refundData = $refundResponse->json()['data'];

        // Update payment record with refund info
        $payment->update([
            'refund_reference' => $refundData['id'] ?? null,
            'refund_status'    => 'pending',
            'refund_amount'    => $order->total,
        ]);

        Log::info('Paystack Refund Initiated', [
            'order'            => $order->reference,
            'refund_reference' => $refundData['id'] ?? null,
        ]);

        return ['success' => true, 'message' => 'Refund initiated successfully.'];
    }

    /**
     * Handle Paystack refund.processed webhook event.
     * Updates the payment and order to reflect the completed refund.
     */
    private function processRefundWebhook(array $data): void
    {
        $refundId = $data['id'] ?? null;
        $txRef = $data['transaction_reference'] ?? null;

        if (!$refundId && !$txRef) return;

        $payment = Payment::where(function ($query) use ($refundId, $txRef) {
            if ($refundId) {
                $query->orWhere('refund_reference', $refundId);
            }
            if ($txRef) {
                $query->orWhere('transaction_reference', $txRef);
            }
        })->first();

        if (!$payment) {
            Log::warning('Refund webhook: no payment found for refund data', ['id' => $refundId, 'tx_ref' => $txRef]);
            return;
        }

        $status = $data['status'] ?? 'failed'; // 'processed' or 'failed'
        $refundStatus = ($status === 'processed') ? 'success' : 'failed';
        $orderPaymentStatus = ($status === 'processed') ? 'refunded' : 'refund_pending';

        $payment->update([
            'refund_status' => $refundStatus,
            'refunded_at'   => now(),
        ]);

        $order = Order::find($payment->order_id);
        if ($order) {
            $order->update(['payment_status' => $orderPaymentStatus]);

            // Notify customer the refund is complete
            try {
                $order->load('user');
                if ($order->user && $status === 'processed') {
                    (new \App\Notifications\OrderStatusUpdate($order))->send($order->user);
                }
            } catch (\Throwable $e) {
                Log::warning('Refund confirmation email failed: ' . $e->getMessage());
            }

            // Notify Admin
            try {
                if ($status === 'processed') {
                    \App\Models\AdminNotification::create([
                        'type'         => 'refund',
                        'message'      => "Refund of ₦" . number_format($payment->refund_amount, 2) . " processed for order #{$order->id} ({$order->reference}).",
                        'reference_id' => (string) $order->id,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Refund admin notification failed: ' . $e->getMessage());
            }
        }

        Log::info('Paystack Refund Webhook Processed', [
            'refund_id' => $refundId ?? $txRef,
            'status'    => $refundStatus,
        ]);
    }
}
