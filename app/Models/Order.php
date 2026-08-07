<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'customer_name', 'customer_email', 'customer_phone',
        'subtotal', 'total', 'status', 'payment_status', 'payment_reference',
        'delivery_address', 'notes',
        'courier_name', 'tracking_number', 'tracking_url', 'status_history', 'pickup_info'
    ];

    protected $casts = [
        'delivery_address' => 'array',
        'status_history' => 'array',
        'pickup_info' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Finds paid orders that have been pending for > 30 mins
     * and updates them to processing, sending the notification.
     */
    public static function processDelayedOrders()
    {
        $orders = self::where('payment_status', 'paid')
                      ->where('status', 'pending')
                      ->where('updated_at', '<=', now()->subMinutes(30))
                      ->get();

        foreach ($orders as $order) {
            $order->status = 'processing';
            
            $history = $order->status_history ?? [];
            $history[] = [
                'status' => 'processing',
                'timestamp' => now()->toIso8601String(),
                'note' => 'Auto-processed after 30 minute delay'
            ];
            $order->status_history = $history;
            $order->save();

            try {
                if ($order->user) {
                    (new \App\Notifications\OrderStatusUpdate($order))->send($order->user);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Delayed order processing email failed: ' . $e->getMessage());
            }
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
