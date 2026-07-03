<?php

namespace App\Notifications;

use App\Models\Product;
use App\Services\BrevoMailService;

class LowStockNotification
{
    public Product $product;

    public function __construct(Product $product)
    {
        $this->product = $product;
    }

    public function send(object $notifiable): bool
    {
        $html = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:30px;background:#fff;border-radius:8px;">
            <h2 style="color:#dc2626;">⚠️ Low Stock Alert</h2>
            <p>Hello Admin,</p>
            <p>The following product is running low on stock:</p>
            <table style="border-collapse:collapse;width:100%;margin:16px 0;">
                <tr><td style="padding:8px;background:#fef2f2;font-weight:bold;">Product</td><td style="padding:8px;">' . e($this->product->name) . '</td></tr>
                <tr><td style="padding:8px;background:#fef2f2;font-weight:bold;">Current Stock</td><td style="padding:8px;color:#dc2626;font-weight:bold;">' . (int) $this->product->stock . ' units remaining</td></tr>
            </table>
            <p style="color:#666;font-size:13px;">Please restock soon to avoid running out.</p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="color:#999;font-size:12px;">Manna Bridal Admin</p>
        </div>';

        return app(BrevoMailService::class)->send(
            ['email' => $notifiable->email, 'name' => $notifiable->name],
            'Low Stock Alert — ' . $this->product->name,
            $html
        );
    }
}
