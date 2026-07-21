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
        <div style="font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;max-width:600px;margin:auto;padding:32px;background:#ffffff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.05);">
            <div style="text-align:center;margin-bottom:24px;">
                <span style="font-size:40px;"></span>
            </div>
            <h2 style="color:#1A1A2E;text-align:center;font-size:24px;margin-bottom:8px;">New Order Placed!</h2>
            <p style="color:#666;text-align:center;font-size:15px;margin-top:0;">You have a new order waiting to be processed.</p>
            
            <div style="background:#FAF3F0;border-radius:12px;padding:24px;margin:32px 0;">
                <table style="border-collapse:collapse;width:100%;">
                    <tr>
                        <td style="padding:12px 0;border-bottom:1px solid #E8E0D8;color:#888;font-size:13px;font-weight:600;text-transform:uppercase;">Order Ref</td>
                        <td style="padding:12px 0;border-bottom:1px solid #E8E0D8;color:#1A1A2E;font-size:15px;font-weight:700;text-align:right;">' . e($this->order->reference) . '</td>
                    </tr>
                    <tr>
                        <td style="padding:12px 0;border-bottom:1px solid #E8E0D8;color:#888;font-size:13px;font-weight:600;text-transform:uppercase;">Customer</td>
                        <td style="padding:12px 0;border-bottom:1px solid #E8E0D8;color:#1A1A2E;font-size:15px;font-weight:600;text-align:right;">' . e($this->order->user->name ?? $this->order->customer_name) . '</td>
                    </tr>
                    <tr>
                        <td style="padding:12px 0;color:#888;font-size:13px;font-weight:600;text-transform:uppercase;">Amount Paid</td>
                        <td style="padding:12px 0;color:#22C55E;font-size:16px;font-weight:800;text-align:right;">' . number_format($this->order->total, 2) . '</td>
                    </tr>
                </table>
            </div>

            <p style="color:#666;font-size:14px;text-align:center;line-height:1.6;">
                The customer has successfully paid for this order.<br/>Please review it in your admin dashboard.
            </p>
            
            <div style="text-align:center;margin-top:32px;">
                <a href="' . config('app.frontend_url', 'https://mannabridal.netlify.app') . '/admin" style="display:inline-block;padding:14px 32px;background:#F47B20;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;font-size:15px;letter-spacing:0.5px;">Go to Dashboard </a>
            </div>
            
            <hr style="border:none;border-top:1px solid #eee;margin:32px 0;">
            <p style="color:#999;font-size:12px;text-align:center;">Manna Bridal Admin System</p>
        </div>';

        return BrevoMailService::send(
            $notifiable->email,
            $notifiable->name,
            ' New Paid Order: ' . $this->order->reference,
            $html
        );
    }
}
