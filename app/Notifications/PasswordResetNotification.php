<?php

namespace App\Notifications;

use App\Services\BrevoMailService;

class PasswordResetNotification
{
    public string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function send(object $notifiable): bool
    {
        $url = config('app.frontend_url', 'http://localhost:3000')
             . '/reset-password?token=' . $this->token
             . '&email=' . urlencode($notifiable->email);

        $html = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:30px;background:#fff;border-radius:8px;">
            <h2 style="color:#7c3aed;">Password Reset Request</h2>
            <p>You are receiving this email because we received a password reset request for your account.</p>
            <a href="' . $url . '"
               style="display:inline-block;padding:12px 24px;background:#7c3aed;color:#fff;text-decoration:none;border-radius:6px;margin:16px 0;">
               Reset Password
            </a>
            <p style="color:#666;font-size:13px;">This link will expire in <strong>60 minutes</strong>.</p>
            <p style="color:#999;font-size:12px;">If you did not request a password reset, no further action is required.</p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="color:#999;font-size:12px;">Manna Bridal</p>
        </div>';

        return app(BrevoMailService::class)->send(
            ['email' => $notifiable->email, 'name' => $notifiable->name ?? ''],
            'Reset Your Manna Bridal Password',
            $html
        );
    }
}
