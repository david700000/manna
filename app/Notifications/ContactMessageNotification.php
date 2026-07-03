<?php

namespace App\Notifications;

use App\Services\BrevoMailService;

class ContactMessageNotification
{
    public array $messageData;

    public function __construct(array $messageData)
    {
        $this->messageData = $messageData;
    }

    public function send(object $notifiable): bool
    {
        $html = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:30px;background:#fff;border-radius:8px;">
            <h2 style="color:#7c3aed;">New Contact Message</h2>
            <p>You have received a new message from the contact form:</p>
            <table style="border-collapse:collapse;width:100%;margin:16px 0;">
                <tr><td style="padding:8px;background:#f3f0ff;font-weight:bold;">Name</td><td style="padding:8px;">' . e($this->messageData['name']) . '</td></tr>
                <tr><td style="padding:8px;background:#f3f0ff;font-weight:bold;">Email</td><td style="padding:8px;"><a href="mailto:' . e($this->messageData['email']) . '">' . e($this->messageData['email']) . '</a></td></tr>
                <tr><td style="padding:8px;background:#f3f0ff;font-weight:bold;vertical-align:top;">Message</td><td style="padding:8px;">' . nl2br(e($this->messageData['message'])) . '</td></tr>
            </table>
            <p style="color:#666;font-size:13px;">Reply directly to <a href="mailto:' . e($this->messageData['email']) . '">' . e($this->messageData['email']) . '</a>.</p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="color:#999;font-size:12px;">Manna Bridal Admin</p>
        </div>';

        return app(BrevoMailService::class)->send(
            ['email' => $notifiable->email, 'name' => $notifiable->name],
            'New Contact Message from ' . $this->messageData['name'],
            $html
        );
    }
}
