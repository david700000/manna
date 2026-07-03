<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * Sends transactional emails via Laravel's default Mail system.
 * (Previously used Brevo REST API directly)
 */
class BrevoMailService
{
    public function __construct()
    {
        // No longer need to fetch Brevo API key, as we use Laravel's Mail facade.
    }

    /**
     * Send a transactional email.
     *
     * @param  string|array  $to       Email address or ['email'=>..,'name'=>..]
     * @param  string        $subject
     * @param  string        $html     HTML body
     * @param  string|null   $text     Optional plain-text fallback
     * @return bool
     */
    public function send(string|array $to, string $subject, string $html, ?string $text = null): bool
    {
        if (is_string($to)) {
            $to = [['email' => $to]];
        } elseif (isset($to['email'])) {
            $to = [$to];
        }

        try {
            foreach ($to as $recipient) {
                Mail::html($html, function ($message) use ($recipient, $subject) {
                    $message->to($recipient['email'], $recipient['name'] ?? null)
                            ->subject($subject);
                });
            }
            return true;
        } catch (\Exception $e) {
            Log::error('Mail sending failed', [
                'error'   => $e->getMessage(),
                'to'      => $to,
                'subject' => $subject,
            ]);
            return false;
        }
    }
}
