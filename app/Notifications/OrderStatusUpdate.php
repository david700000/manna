<?php

namespace App\Notifications;

use App\Models\Order;
use App\Services\BrevoMailService;

class OrderStatusUpdate
{
    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function send(object $notifiable): bool
    {
        $status   = $this->order->status;
        $ref      = $this->order->reference;
        $shopUrl  = config('app.frontend_url', 'https://mannabridal.netlify.app');

        $statusLabels = [
            'pending'    => ['label' => 'Order Placed',       'color' => '#F59E0B', 'icon' => '📦'],
            'processing' => ['label' => 'Being Processed',    'color' => '#3B82F6', 'icon' => '⚙️'],
            'shipped'    => ['label' => 'Shipped',             'color' => '#8B5CF6', 'icon' => '🚚'],
            'delivered'  => ['label' => 'Delivered',           'color' => '#10B981', 'icon' => '✅'],
            'paid'       => ['label' => 'Payment Confirmed',   'color' => '#10B981', 'icon' => '💳'],
            'cancelled'  => ['label' => 'Cancelled',           'color' => '#EF4444', 'icon' => '❌'],
            'failed'     => ['label' => 'Payment Failed',      'color' => '#EF4444', 'icon' => '⚠️'],
        ];

        $info = $statusLabels[$status] ?? ['label' => ucfirst($status), 'color' => '#F47B20', 'icon' => '📋'];

        $html = '
        <div style="font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;max-width:600px;margin:auto;padding:32px;background:#ffffff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.05);">
          <div style="text-align:center;margin-bottom:24px;">
            <div style="display:inline-block;background:#FAF3F0;color:#F47B20;font-size:24px;font-weight:900;padding:12px 24px;border-radius:12px;letter-spacing:1px;">MANNA BRIDAL ✨</div>
          </div>
          <h2 style="color:#1A1A2E;text-align:center;font-size:24px;margin-bottom:8px;">' . $info['icon'] . ' Order Update</h2>
          <p style="color:#666;text-align:center;font-size:15px;margin-top:0;">Hello <strong>' . e($notifiable->name) . '</strong>,</p>
          <p style="color:#666;text-align:center;font-size:15px;">Your order <strong>' . e($ref) . '</strong> status has been updated.</p>
          
          <div style="background:#FAF3F0;border-radius:12px;padding:24px;margin:32px 0;text-align:center;">
            <p style="margin:0;font-size:13px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Current Status</p>
            <p style="margin:8px 0 0;font-size:24px;font-weight:900;color:' . $info['color'] . ';">' . $info['label'] . '</p>
            <p style="margin:6px 0 0;font-size:13px;color:#aaa;">Order Ref: ' . e($ref) . '</p>
          </div>
          
          <div style="text-align:center;margin-top:32px;">
            <a href="' . $shopUrl . '"
               style="display:inline-block;padding:14px 36px;background:#F47B20;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;font-size:15px;letter-spacing:0.5px;box-shadow:0 4px 12px rgba(244,123,32,0.2);">
               Track My Order →
            </a>
          </div>
          
          <p style="color:#888;font-size:14px;text-align:center;margin-top:32px;">Thank you for shopping with Manna Bridal!</p>
          <hr style="border:none;border-top:1px solid #E8E0D8;margin:32px 0;">
          <p style="color:#999;font-size:12px;text-align:center;text-transform:uppercase;letter-spacing:1px;">Manna Bridal &mdash; Premium Bridal Collections</p>
        </div>';

        return BrevoMailService::send(
            $notifiable->email,
            $notifiable->name,
            $info['icon'] . ' Order ' . e($ref) . ' — ' . $info['label'],
            $html
        );
    }
}
