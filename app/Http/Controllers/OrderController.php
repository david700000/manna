<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Notifications\OrderStatusUpdate;
use App\Notifications\OrderPlacedAdminNotification;
use App\Notifications\LowStockNotification;
use App\Models\ActivityLog;
use App\Http\Controllers\PaymentController;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.size' => 'nullable|string|max:20',
            'shipping_address' => 'required|string',
            'billing_address' => 'required|string',
            'customer_phone' => 'nullable|string',
            'state' => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            $totalAmount = 0;
            $orderItems = [];

            // ── Stock validation pass (before any writes) ──────────────────
            foreach ($validated['items'] as $item) {
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);

                if ($product->stock < $item['quantity']) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Insufficient stock for \"{$product->name}\". Only {$product->stock} unit(s) available.",
                        'product_id' => $product->id,
                    ], 422);
                }
            }

            // ── Calculate total and prepare items ──────────────────────────
            foreach ($validated['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);

                $price = $product->price;
                $totalAmount += $price * $item['quantity'];

                // Decode images array if stored as JSON string, otherwise array
                $imgs = [];
                if (is_array($product->images)) {
                    $imgs = $product->images;
                } elseif (is_string($product->images)) {
                    $imgs = json_decode($product->images, true) ?: [];
                }
                $firstImage = count($imgs) > 0 ? $imgs[0] : null;

                $orderItems[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'price' => $price,
                    'quantity' => $item['quantity'],
                    'size' => $item['size'] ?? null,
                    'image_url' => $firstImage,
                ];
            }

            $user = $request->user();

            if (empty($user->phone) && !empty($validated['customer_phone'])) {
                $user->phone = $validated['customer_phone'];
                $user->save();
            }

            $state = strtolower(trim($validated['state']));
            $shippingFee = ($state === 'lagos' || $state === 'kwara') ? 2000 : 4000;
            
            $grandTotal = $totalAmount + $shippingFee;

            // Create Order using migration schema columns
            $order = Order::create([
                'reference' => 'ORD-' . strtoupper(Str::random(10)),
                'user_id' => $user->id,
                'customer_name' => $user->name,
                'customer_email' => $user->email,
                'customer_phone' => $validated['customer_phone'] ?? $user->phone ?? '',
                'subtotal' => $totalAmount,
                'shipping_fee' => $shippingFee,
                'total' => $grandTotal,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'delivery_address' => [
                    'shipping_address' => $validated['shipping_address'],
                    'billing_address' => $validated['billing_address'],
                ],
                'notes' => null,
                'status_history' => [
                    [
                        'status' => 'pending',
                        'timestamp' => now()->toIso8601String()
                    ]
                ],
            ]);

            // Insert Items and decrement stock
            foreach ($orderItems as $item) {
                $order->items()->create($item);

                // Decrement stock atomically
                Product::where('id', $item['product_id'])
                    ->decrement('stock', $item['quantity']);
            }

            DB::commit();

            // Load relations for notifications and response
            $order->load('items.product', 'user');

            ActivityLog::log($user->id, 'place_order', "Order placed: {$order->reference} — Total: ₦" . number_format($totalAmount, 2), $request->ip());

            // ── Fire admin notification ────────────────────────────────────
            try {
                \App\Models\AdminNotification::create([
                    'type'         => 'order',
                    'message'      => "New order #{$order->id} placed by {$user->name} — ₦" . number_format($grandTotal, 2),
                    'reference_id' => (string) $order->id,
                ]);
            } catch (\Throwable $e) {}

            return response()->json($order, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to place order', 'error' => $e->getMessage()], 500);
        }
    }

    public function index(Request $request)
    {
        $orders = $request->user()->orders()->with('items.product')->latest()->get();
        return response()->json($orders);
    }

    public function show(Request $request, $id)
    {
        $order = $request->user()->orders()->with('items.product')->findOrFail($id);
        return response()->json($order);
    }

    public function confirmDelivery(Request $request, $id)
    {
        $order = $request->user()->orders()->findOrFail($id);

        if ($order->status === 'delivered') {
            return response()->json(['message' => 'Order is already marked as delivered.'], 400);
        }

        $history = $order->status_history ?? [];
        $history[] = [
            'status' => 'delivered',
            'timestamp' => now()->toIso8601String(),
        ];

        $order->update([
            'status' => 'delivered',
            'status_history' => $history
        ]);

        // Send notification to admin/system about customer confirmation
        try {
            $msg = 'Order ' . $order->reference . ' has been confirmed as RECEIVED by the customer.';
            
            // Insert chat message
            \App\Models\SupportMessage::create([
                'user_id' => $request->user()->id,
                'session_id' => null,
                'is_admin_reply' => false,
                'message' => 'SYSTEM: Customer confirmed order delivery.'
            ]);

            $admins = \App\Models\User::where('role', 'admin')->get();
            foreach ($admins as $adminUser) {
                (new \App\Notifications\ChatNotification([
                    'name' => 'System',
                    'message' => $msg
                ], 'admin'))->send($adminUser);
            }
        } catch (\Throwable $e) {}

        return response()->json(['message' => 'Delivery confirmed successfully', 'order' => $order]);
    }

    public function cancelOrder(Request $request, $id)
    {
        $order = $request->user()->orders()->with('items.product')->findOrFail($id);

        $nonCancellableStatuses = ['shipped', 'in transit', 'delivered', 'cancelled'];
        if (in_array($order->status, $nonCancellableStatuses)) {
            return response()->json([
                'message' => "Order cannot be cancelled because it is already {$order->status}."
            ], 400);
        }

        $history = $order->status_history ?? [];
        $history[] = [
            'status'    => 'cancelled',
            'timestamp' => now()->toIso8601String(),
        ];

        $order->update([
            'status'         => 'cancelled',
            'status_history' => $history,
        ]);

        // ── Restore stock for every item in the order ─────────────────────
        foreach ($order->items as $item) {
            Product::where('id', $item->product_id)
                ->increment('stock', $item->quantity);
        }

        // ── Trigger refund if the order was already paid ───────────────────
        $refundMessage = null;
        if ($order->payment_status === 'paid') {
            try {
                $paymentController = new PaymentController();
                $result = $paymentController->refund($order);

                if ($result['success']) {
                    $order->update(['payment_status' => 'refund_pending']);
                    $refundMessage = 'A refund of ₦' . number_format($order->total, 2) . ' has been initiated and will be processed within 5–10 business days.';
                    // Fire admin refund notification
                    try {
                        \App\Models\AdminNotification::create([
                            'type'         => 'refund',
                            'message'      => "Refund of ₦" . number_format($order->total, 2) . " initiated for order #{$order->id} ({$order->customer_name}).",
                            'reference_id' => (string) $order->id,
                        ]);
                    } catch (\Throwable $e) {}
                } else {
                    Log::warning('Refund initiation failed for order ' . $order->reference . ': ' . $result['message']);
                    $refundMessage = 'Order cancelled. We could not automatically initiate a refund — our team will process it manually within 24 hours.';
                }
            } catch (\Throwable $e) {
                Log::error('Refund exception for order ' . $order->reference . ': ' . $e->getMessage());
                $refundMessage = 'Order cancelled. Refund will be processed manually by our team.';
            }
        }

        // ── Send cancellation notification email ───────────────────────────
        try {
            $order->load('user');
            if ($order->user) {
                (new \App\Notifications\OrderStatusUpdate($order))->send($order->user);
            }
        } catch (\Throwable $e) {
            Log::warning('Order cancel email failed: ' . $e->getMessage());
        }

        return response()->json([
            'message'        => 'Order cancelled successfully.',
            'refund_message' => $refundMessage,
            'order'          => $order->fresh(),
        ]);
    }

    public function rateProduct(Request $request, $id)
    {
        $validated = $request->validate([
            'ratings' => 'required|array',
            'ratings.*.product_id' => 'required|exists:products,id',
            'ratings.*.rating' => 'required|integer|min:1|max:5',
            'ratings.*.review' => 'nullable|string'
        ]);

        $order = $request->user()->orders()->findOrFail($id);

        if ($order->status !== 'delivered') {
            return response()->json(['message' => 'You can only rate delivered orders.'], 400);
        }

        foreach ($validated['ratings'] as $item) {
            \App\Models\ProductRating::updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                ],
                [
                    'rating' => $item['rating'],
                    'review' => $item['review'] ?? null,
                ]
            );
        }

        return response()->json(['message' => 'Ratings submitted successfully.']);
    }
}
