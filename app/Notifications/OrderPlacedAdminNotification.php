<?php

namespace App\Notifications;

use App\Models\Order;
use App\Services\BrevoMailService;

class OrderPlacedAdminNotification
{
    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function send(object $notifiable): bool
    {
        $html = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:30px;background:#fff;border-radius:8px;">
            <h2 style="color:#7c3aed;">New Order Received 🛍</h2>
            <p>Hello Admin,</p>
            <p>A new order has been placed:</p>
            <table style="border-collapse:collapse;width:100%;margin:16px 0;">
                <tr><td style="padding:8px;background:#f3f0ff;font-weight:bold;">Customer</td><td style="padding:8px;">' . e($this->order->user->name) . ' (' . e($this->order->user->email) . ')</td></tr>
                <tr><td style="padding:8px;background:#f3f0ff;font-weight:bold;">Order Reference</td><td style="padding:8px;">' . e($this->order->reference) . '</td></tr>
                <tr><td style="padding:8px;background:#f3f0ff;font-weight:bold;">Total Amount</td><td style="padding:8px;">₦' . number_format($this->order->total, 2) . '</td></tr>
            </table>
            <p style="color:#666;font-size:13px;">Please review and process the order.</p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="color:#999;font-size:12px;">Manna Bridal Admin</p>
        </div>';

        return BrevoMailService::send(
            $notifiable->email,
            $notifiable->name,
            'New Order — ' . $this->order->reference,
            $html
        );
    }
}
