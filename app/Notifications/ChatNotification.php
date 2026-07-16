<?php

namespace App\Notifications;

use App\Services\BrevoMailService;

class ChatNotification
{
    protected $data;
    protected $type;

    /**
     * @param array $data Contains name, email, message
     * @param string $type 'admin' (notify admin) or 'customer' (notify customer)
     */
    public function __construct(array $data, string $type = 'admin')
    {
        $this->data = $data;
        $this->type = $type;
    }

    public function send(object $notifiable): bool
    {
        if ($this->type === 'admin') {
            // Customer sent a message to Admin
            $subject = 'New Chat Message from ' . ($this->data['name'] ?? 'Customer');
            $headerTitle = 'New Chat Message 💬';
            $introText = 'Hello Admin,<br><br><strong>' . e($this->data['name'] ?? 'Customer') . '</strong> has sent a new message in the chat:';
        } else {
            // Admin replied to Customer
            $subject = 'Seller responded to your message';
            $headerTitle = 'New Message from Manna Bridal 💬';
            $introText = 'Hello <strong>' . e($this->data['name'] ?? 'Customer') . '</strong>,<br><br>The seller has responded to your message:';
        }

        $html = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:30px;background:#fff;border-radius:8px;border:1px solid #eee;">
            <h2 style="color:#F47B20;">' . $headerTitle . '</h2>
            <p style="color:#333;font-size:14px;line-height:1.6;">' . $introText . '</p>
            <div style="margin:20px 0;padding:15px;background:#f9f9f9;border-left:4px solid #F47B20;font-style:italic;color:#555;">
                "' . nl2br(e($this->data['message'])) . '"
            </div>
            <p style="color:#666;font-size:13px;line-height:1.6;">Log in to the app to view the full conversation and reply.</p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="color:#999;font-size:12px;">Manna Bridal</p>
        </div>';

        return BrevoMailService::send(
            $notifiable->email,
            $notifiable->name ?? 'User',
            $subject,
            $html
        );
    }
}
