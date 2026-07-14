<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class BrevoMailService
{
    /**
     * Send a transactional email via Brevo REST API.
     */
    public function send(string|array $to, string $subject, string $html, ?string $text = null): bool
    {
        if (is_string($to)) {
            $to = [['email' => $to]];
        } elseif (isset($to['email'])) {
            $to = [$to];
        }

        $apiKey = env('BREVO_API_KEY');
        if (!$apiKey) {
            Log::error('Mail sending failed: BREVO_API_KEY is not set.');
            return false;
        }

        $senderEmail = env('MAIL_FROM_ADDRESS', 'mannabridalsupport@gmail.com');
        $senderName  = env('MAIL_FROM_NAME', 'Manna Bridal');

        try {
            $response = Http::withHeaders([
                'api-key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->post('https://api.brevo.com/v3/smtp/email', [
                'sender'      => ['name' => $senderName, 'email' => $senderEmail],
                'to'          => $to,
                'subject'     => $subject,
                'htmlContent' => $html,
            ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('Mail sending failed via Brevo API', [
                'status'   => $response->status(),
                'response' => $response->json(),
                'to'       => $to,
                'subject'  => $subject,
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('Mail sending failed via Brevo API (Exception)', [
                'error'   => $e->getMessage(),
                'to'      => $to,
                'subject' => $subject,
            ]);
            return false;
        }
    }
}
