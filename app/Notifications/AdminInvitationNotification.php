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
        $html = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:30px;background:#fff;border-radius:8px;">
            <h2 style="color:#7c3aed;">Manna Bridal — Team Invitation</h2>
            <p>Hello <strong>' . e($notifiable->name) . '</strong>,</p>
            <p>You have been invited to join the Manna Bridal team as a <strong>' . ucfirst(e($notifiable->role)) . '</strong>.</p>
            <p>Here are your temporary login credentials:</p>
            <table style="border-collapse:collapse;width:100%;margin:16px 0;">
                <tr><td style="padding:8px;background:#f3f0ff;font-weight:bold;">Email</td><td style="padding:8px;">' . e($notifiable->email) . '</td></tr>
                <tr><td style="padding:8px;background:#f3f0ff;font-weight:bold;">Temporary Password</td><td style="padding:8px;">' . e($this->tempPassword) . '</td></tr>
            </table>
            <a href="http://localhost:3000/login"
               style="display:inline-block;padding:12px 24px;background:#7c3aed;color:#fff;text-decoration:none;border-radius:6px;margin:16px 0;">
               Login to Dashboard
            </a>
            <p style="color:#666;font-size:13px;">You will be required to change your password on first login.</p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="color:#999;font-size:12px;">Manna Bridal &mdash; Admin Portal</p>
        </div>';

        return app(BrevoMailService::class)->send(
            ['email' => $notifiable->email, 'name' => $notifiable->name],
            'Manna Bridal — Administrative Invitation',
            $html
        );
    }
}
