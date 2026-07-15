<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Setting;

class VerifyUnpaidOrders extends Command
{
    protected $signature = 'orders:verify-paid {--limit=50}';

    protected $description = 'Re-verify payments on Paystack for orders that still show as unpaid but may have been paid.';

    private $baseUrl = 'https://api.paystack.co';

    private function getSecretKey()
    {
        $setting = Setting::where('key', 'paystack_secret_key')->first();
        return $setting ? $setting->value : env('PAYSTACK_SECRET_KEY');
    }

    public function handle()
    {
        $secretKey = $this->getSecretKey();

        if (!$secretKey) {
            $this->error('Paystack secret key not configured.');
            return 1;
        }

        $limit = (int) $this->option('limit');

        // Get all orders that are not paid
        $orders = Order::whereNotIn('payment_status', ['paid'])
            ->latest()
            ->limit($limit)
            ->get();

        $this->info("Checking {$orders->count()} non-paid orders against Paystack...");

        $updatedCount = 0;

        foreach ($orders as $order) {
            // Fetch all payment attempts on Paystack for this order's reference pattern
            // Paystack allows listing transactions filtered by reference prefix
            $response = Http::withToken($secretKey)
                ->get($this->baseUrl . '/transaction', [
                    'perPage'   => 20,
                    'page'      => 1,
                    'reference' => $order->reference,
                ]);

            if (!$response->successful()) {
                $this->line("  ⚠ Could not query Paystack for order {$order->reference}");
                continue;
            }

            $transactions = $response->json()['data'] ?? [];

            $foundPaid = false;
            foreach ($transactions as $txn) {
                if ($txn['status'] === 'success') {
                    $foundPaid = true;

                    // Mark order as paid
                    $order->update(['payment_status' => 'paid', 'status' => 'paid']);

                    // Record the payment if not already recorded
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

                    $updatedCount++;
                    $this->line("  ✓ Order {$order->reference} marked as PAID (txn: {$txn['reference']})");
                    break;
                }
            }

            if (!$foundPaid && count($transactions) === 0) {
                $this->line("  - Order {$order->reference}: No Paystack transactions found.");
            } elseif (!$foundPaid) {
                $this->line("  - Order {$order->reference}: Paystack has transactions but none successful.");
            }
        }

        $this->info("Done. {$updatedCount} order(s) updated to paid.");
        return 0;
    }
}
