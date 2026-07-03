<?php

namespace App\Notifications;

use App\Services\BrevoMailService;

class WelcomeUser
{
    public function send(object $notifiable): bool
    {
        $shopUrl = config('app.frontend_url', 'http://localhost:3000');

        $html = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:30px;background:#fff;border-radius:8px;">
            <h2 style="color:#7c3aed;">Welcome to Manna Bridal! 💍</h2>
            <p>Hello <strong>' . e($notifiable->name) . '</strong>,</p>
            <p>We are thrilled to have you here. Explore our exclusive collection of bridal dresses and accessories crafted for your special day.</p>
            <a href="' . $shopUrl . '/shop"
               style="display:inline-block;padding:12px 24px;background:#7c3aed;color:#fff;text-decoration:none;border-radius:6px;margin:16px 0;">
               Shop Now
            </a>
            <p style="color:#666;font-size:13px;">Thank you for joining the Manna Bridal family!</p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="color:#999;font-size:12px;">Manna Bridal</p>
        </div>';

        return app(BrevoMailService::class)->send(
            ['email' => $notifiable->email, 'name' => $notifiable->name],
            'Welcome to Manna Bridal',
            $html
        );
    }
}
