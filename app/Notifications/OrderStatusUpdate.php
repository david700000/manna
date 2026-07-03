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
        $statusMsg = $this->order->status === 'pending'
            ? 'has been placed successfully.'
            : 'status has been updated to: <strong>' . ucfirst($this->order->status) . '</strong>.';

        $html = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:30px;background:#fff;border-radius:8px;">
            <h2 style="color:#7c3aed;">Order Update</h2>
            <p>Hello <strong>' . e($notifiable->name) . '</strong>,</p>
            <p>Your order <strong>' . e($this->order->reference) . '</strong> ' . $statusMsg . '</p>
            <table style="border-collapse:collapse;width:100%;margin:16px 0;">
                <tr><td style="padding:8px;background:#f3f0ff;font-weight:bold;">Order Reference</td><td style="padding:8px;">' . e($this->order->reference) . '</td></tr>
                <tr><td style="padding:8px;background:#f3f0ff;font-weight:bold;">Status</td><td style="padding:8px;">' . ucfirst(e($this->order->status)) . '</td></tr>
            </table>
            <p style="color:#666;font-size:13px;">Thank you for shopping with Manna Bridal!</p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="color:#999;font-size:12px;">Manna Bridal</p>
        </div>';

        return app(BrevoMailService::class)->send(
            ['email' => $notifiable->email, 'name' => $notifiable->name],
            'Order Update — ' . $this->order->reference,
            $html
        );
    }
}
