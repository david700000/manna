<?php

namespace App\Notifications;

use App\Services\BrevoMailService;

class PasswordResetNotification
{
    public string $token;
    public bool   $isOtp;

    public function __construct(string $token, bool $isOtp = false)
    {
        $this->token = $token;
        $this->isOtp = $isOtp;
    }

    public function send(object $notifiable): bool
    {
        if ($this->isOtp) {
            // ── OTP email ──────────────────────────────────────────────────
            $otp = $this->token;
            $html = '
            <div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;padding:32px 24px;background:#fff;border-radius:12px;">
              <div style="text-align:center;margin-bottom:24px;">
                <img src="https://manna-qhim.onrender.com/logo.png" alt="Manna Bridal" style="height:50px;object-fit:contain;" />
              </div>
              <h2 style="color:#1A1A2E;font-size:20px;margin-bottom:8px;text-align:center;">Password Reset OTP</h2>
              <p style="color:#555;font-size:14px;text-align:center;margin-bottom:24px;">
                Use the code below to reset your Manna Bridal password.<br>It expires in <strong>10 minutes</strong>.
              </p>
              <div style="background:#FAF3F0;border:2px dashed #F47B20;border-radius:10px;padding:24px;text-align:center;margin-bottom:24px;">
                <span style="font-size:40px;font-weight:900;letter-spacing:12px;color:#F47B20;">' . $otp . '</span>
              </div>
              <p style="color:#999;font-size:12px;text-align:center;">If you did not request a password reset, please ignore this email.</p>
              <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
              <p style="color:#bbb;font-size:11px;text-align:center;">Manna Bridal &mdash; Premium Bridal Collections</p>
            </div>';

            return app(BrevoMailService::class)->send(
                ['email' => $notifiable->email, 'name' => $notifiable->name ?? ''],
                'Your Manna Bridal Password Reset Code: ' . $otp,
                $html
            );
        }

        // ── Legacy link email (kept for compatibility) ─────────────────────
        $url = config('app.frontend_url', 'http://localhost:3000')
             . '/reset-password?token=' . $this->token
             . '&email=' . urlencode($notifiable->email);

        $html = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:30px;background:#fff;border-radius:8px;">
            <h2 style="color:#F47B20;">Password Reset Request</h2>
            <p>You are receiving this email because we received a password reset request for your account.</p>
            <a href="' . $url . '"
               style="display:inline-block;padding:12px 24px;background:#F47B20;color:#fff;text-decoration:none;border-radius:6px;margin:16px 0;">
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
