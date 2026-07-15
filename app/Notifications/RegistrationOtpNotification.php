<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class RegistrationOtpNotification extends Notification
{
    use Queueable;

    protected $otp;
    protected $name;

    public function __construct($otp, $name)
    {
        $this->otp = $otp;
        $this->name = $name;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Verify your email address')
            ->greeting('Hello ' . $this->name . ',')
            ->line('Thank you for registering at Manna Bridal!')
            ->line('To complete your registration, please use the following One-Time Password (OTP) to verify your email address. This OTP will expire in 15 minutes.')
            ->line(new HtmlString('<div style="text-align:center; padding:15px; margin:20px 0; font-size:24px; font-weight:bold; letter-spacing:4px; background-color:#f8f9fa; border:1px dashed #ced4da; border-radius:8px;">' . $this->otp . '</div>'))
            ->line('If you did not create an account, no further action is required.');
    }
}
