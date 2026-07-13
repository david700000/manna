<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use App\Notifications\OrderStatusUpdate;
use App\Notifications\OrderPlacedAdminNotification;
use App\Notifications\LowStockNotification;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'shipping_address' => 'required|string',
            'billing_address' => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            $totalAmount = 0;
            $orderItems = [];

            // Calculate total and prepare items
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
                    'image_url' => $firstImage,
                ];
            }

            $user = $request->user();

            // Create Order using migration schema columns
            $order = Order::create([
                'reference' => 'ORD-' . strtoupper(Str::random(10)),
                'user_id' => $user->id,
                'customer_name' => $user->name,
                'customer_email' => $user->email,
                'customer_phone' => $user->phone ?? '',
                'subtotal' => $totalAmount,
                'total' => $totalAmount,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'delivery_address' => [
                    'shipping_address' => $validated['shipping_address'],
                    'billing_address' => $validated['billing_address'],
                ],
                'notes' => null,
            ]);

            // Insert Items
            foreach ($orderItems as $item) {
                $order->items()->create($item);
            }

            DB::commit();

            // Load relations for notifications and response
            $order->load('items.product', 'user');

            // Send notification to customer
            try { (new OrderStatusUpdate($order))->send($user); } catch (\Throwable $e) {}

            // Send notification to admin
            $adminUser = (object)['email' => 'mannabridalsupport@gmail.com', 'name' => 'Admin'];
            try { (new OrderPlacedAdminNotification($order))->send($adminUser); } catch (\Throwable $e) {}

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
}
