<?php

namespace App\Notifications;

use App\Services\BrevoMailService;

class AdminInvitationNotification
{
    protected string $tempPassword;

    public function __construct(string $tempPassword)
    {
        $this->tempPassword = $tempPassword;
    }

    public function send(object $notifiable): bool
    {
        $loginUrl = config('app.frontend_url', 'https://mannabridal.netlify.app');

        $html = '
        <div style="font-family:Arial,sans-serif;max-width:580px;margin:auto;padding:32px 24px;background:#fff;border-radius:12px;">
          <div style="text-align:center;margin-bottom:24px;">
            <div style="display:inline-block;background:#F47B20;color:#fff;font-size:22px;font-weight:900;padding:10px 22px;border-radius:8px;letter-spacing:1px;">MANNA BRIDAL</div>
          </div>
          <h2 style="color:#1A1A2E;font-size:20px;margin-bottom:8px;">You\'ve been invited! 🎉</h2>
          <p style="color:#444;font-size:14px;">Hello <strong>' . e($notifiable->name) . '</strong>,</p>
          <p style="color:#444;font-size:14px;">You have been invited to join the <strong>Manna Bridal</strong> admin team as a <strong>' . ucfirst(e($notifiable->role)) . '</strong>.</p>
          <p style="color:#444;font-size:14px;">Here are your one-time login credentials:</p>
          <div style="background:#FAF3F0;border-left:4px solid #F47B20;border-radius:8px;padding:16px 20px;margin:16px 0;">
            <p style="margin:4px 0;font-size:14px;"><strong>Email:</strong> ' . e($notifiable->email) . '</p>
            <p style="margin:4px 0;font-size:14px;"><strong>Temporary Password:</strong> <code style="background:#fff;border:1px solid #eee;padding:2px 8px;border-radius:4px;font-size:15px;letter-spacing:1px;">' . e($this->tempPassword) . '</code></p>
          </div>
          <a href="' . $loginUrl . '"
             style="display:inline-block;padding:13px 28px;background:#F47B20;color:#fff;text-decoration:none;border-radius:8px;margin:12px 0;font-weight:bold;font-size:14px;">
             Login to Dashboard →
          </a>
          <p style="color:#888;font-size:12px;margin-top:16px;">⚠️ You will be required to change this password on your first login.</p>
          <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
          <p style="color:#bbb;font-size:11px;text-align:center;">Manna Bridal &mdash; Admin Portal</p>
        </div>';

        return BrevoMailService::send(
            $notifiable->email,
            $notifiable->name,
            'Manna Bridal — You\'ve Been Invited to the Admin Team',
            $html
        );
    }
}
