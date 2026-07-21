<?php

namespace App\Notifications;

use App\Services\BrevoMailService;

class WelcomeUser
{
    public function send(object $notifiable): bool
    {
        $shopUrl = config('app.frontend_url', 'https://mannabridal.netlify.app');

        $html = '
        <div style="font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;max-width:600px;margin:auto;padding:32px;background:#ffffff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.05);">
          <div style="text-align:center;margin-bottom:24px;">
            <div style="display:inline-block;background:#FAF3F0;color:#F47B20;font-size:24px;font-weight:900;padding:12px 24px;border-radius:12px;letter-spacing:1px;">MANNA BRIDAL </div>
          </div>
          <h2 style="color:#1A1A2E;font-size:26px;text-align:center;margin-bottom:8px;">Welcome, ' . e($notifiable->name) . '! </h2>
          <p style="color:#666;font-size:15px;line-height:1.7;text-align:center;margin-top:0;">We are absolutely thrilled to have you here.</p>
          
          <div style="background:#FAF3F0;border-radius:12px;padding:24px;margin:32px 0;text-align:center;">
             <p style="color:#1A1A2E;font-size:15px;line-height:1.6;margin:0;">
                Explore our exclusive collection of premium bridal gowns, delicate veils, and stunning accessories  carefully curated for your most special day. 
             </p>
          </div>
          
          <div style="text-align:center;margin-top:32px;">
            <a href="' . $shopUrl . '"
               style="display:inline-block;padding:14px 36px;background:#F47B20;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;font-size:15px;letter-spacing:0.5px;box-shadow:0 4px 12px rgba(244,123,32,0.2);">
               Explore Collection 
            </a>
          </div>
          
          <p style="color:#888;font-size:14px;text-align:center;margin-top:32px;">Thank you for joining the Manna Bridal family! </p>
          <hr style="border:none;border-top:1px solid #E8E0D8;margin:32px 0;">
          <p style="color:#999;font-size:12px;text-align:center;text-transform:uppercase;letter-spacing:1px;">Manna Bridal - Premium Bridal Collections</p>
        </div>';

        return BrevoMailService::send(
            $notifiable->email,
            $notifiable->name,
            'Welcome to Manna Bridal  Your Journey Starts Here ',
            $html
        );
    }
}
