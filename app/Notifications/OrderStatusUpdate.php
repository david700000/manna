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
        <div style="font-family:Arial,sans-serif;max-width:580px;margin:auto;padding:32px 24px;background:#fff;border-radius:12px;">
          <div style="text-align:center;margin-bottom:24px;">
            <div style="display:inline-block;background:#F47B20;color:#fff;font-size:22px;font-weight:900;padding:10px 22px;border-radius:8px;letter-spacing:1px;">MANNA BRIDAL</div>
          </div>
          <h2 style="color:#1A1A2E;font-size:20px;">' . $info['icon'] . ' Order Update</h2>
          <p style="color:#444;font-size:14px;">Hello <strong>' . e($notifiable->name) . '</strong>,</p>
          <p style="color:#444;font-size:14px;">Your order <strong>' . e($ref) . '</strong> status has been updated.</p>
          <div style="background:#FAF3F0;border-radius:10px;padding:20px;margin:16px 0;text-align:center;">
            <p style="margin:0;font-size:13px;color:#888;font-weight:bold;text-transform:uppercase;letter-spacing:1px;">Current Status</p>
            <p style="margin:8px 0 0;font-size:22px;font-weight:900;color:' . $info['color'] . ';">' . $info['label'] . '</p>
            <p style="margin:6px 0 0;font-size:12px;color:#aaa;">Order Ref: ' . e($ref) . '</p>
          </div>
          <a href="' . $shopUrl . '"
             style="display:inline-block;padding:13px 28px;background:#F47B20;color:#fff;text-decoration:none;border-radius:8px;margin:8px 0;font-weight:bold;font-size:14px;">
             Track My Order →
          </a>
          <p style="color:#888;font-size:13px;margin-top:16px;">Thank you for shopping with Manna Bridal!</p>
          <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
          <p style="color:#bbb;font-size:11px;text-align:center;">Manna Bridal &mdash; Premium Bridal Collections</p>
        </div>';

        return BrevoMailService::send(
            $notifiable->email,
            $notifiable->name,
            $info['icon'] . ' Order ' . e($ref) . ' — ' . $info['label'],
            $html
        );
    }
}
