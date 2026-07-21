<?php

namespace App\Notifications;

use App\Services\BrevoMailService;

class MarketingOffer
{
    public array $offerData;

    public function __construct(array $offerData)
    {
        $this->offerData = $offerData;
    }

    public function send(object $notifiable): bool
    {
        $shopUrl = config('app.frontend_url', 'http://localhost:3000');

        $html = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:30px;background:#fff;border-radius:8px;">
            <h2 style="color:#7c3aed;"> Manna Bridal  Exclusive Offer</h2>
            <p>Hello <strong>' . e($notifiable->name) . '</strong>,</p>
            <p>' . nl2br(e($this->offerData['message'])) . '</p>
            <a href="' . $shopUrl . '/shop"
               style="display:inline-block;padding:12px 24px;background:#7c3aed;color:#fff;text-decoration:none;border-radius:6px;margin:16px 0;">
               Shop Now
            </a>
            <p style="color:#666;font-size:13px;">Thank you for being a valued customer!</p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="color:#999;font-size:12px;">Manna Bridal  You are receiving this because you are a registered customer.</p>
        </div>';

        return BrevoMailService::send(
            $notifiable->email,
            $notifiable->name,
            $this->offerData['subject'],
            $html
        );
    }
}
