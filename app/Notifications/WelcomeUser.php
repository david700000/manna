<?php

namespace App\Notifications;

use App\Services\BrevoMailService;

class WelcomeUser
{
    public function send(object $notifiable): bool
    {
        $shopUrl = config('app.frontend_url', 'https://mannabridal.netlify.app');

        $html = '
        <div style="font-family:Arial,sans-serif;max-width:580px;margin:auto;padding:32px 24px;background:#fff;border-radius:12px;">
          <div style="text-align:center;margin-bottom:24px;">
            <div style="display:inline-block;background:#F47B20;color:#fff;font-size:22px;font-weight:900;padding:10px 22px;border-radius:8px;letter-spacing:1px;">MANNA BRIDAL</div>
          </div>
          <h2 style="color:#1A1A2E;font-size:22px;">Welcome, ' . e($notifiable->name) . '! 💍</h2>
          <p style="color:#555;font-size:14px;line-height:1.7;">We are so thrilled to have you here. Explore our exclusive collection of bridal gowns, veils, and accessories — all crafted for your most special day.</p>
          <a href="' . $shopUrl . '"
             style="display:inline-block;padding:13px 28px;background:#F47B20;color:#fff;text-decoration:none;border-radius:8px;margin:16px 0;font-weight:bold;font-size:14px;">
             Explore Collection →
          </a>
          <p style="color:#888;font-size:13px;">Thank you for joining the Manna Bridal family! 🌸</p>
          <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
          <p style="color:#bbb;font-size:11px;text-align:center;">Manna Bridal &mdash; Premium Bridal Collections</p>
        </div>';

        return app(BrevoMailService::class)->send(
            ['email' => $notifiable->email, 'name' => $notifiable->name],
            'Welcome to Manna Bridal — Your Journey Starts Here 💍',
            $html
        );
    }
}
