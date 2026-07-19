<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BrevoMailService
 *
 * Sends transactional emails via Brevo's REST API instead of SMTP.
 * This avoids SMTP connection timeouts on environments like Render.
 */
class BrevoMailService
{
    private const API_URL = 'https://api.brevo.com/v3/smtp/email';

    /**
     * Send a transactional email via Brevo's HTTP API.
     *
     * @param  string       $toEmail
     * @param  string       $toName
     * @param  string       $subject
     * @param  string       $htmlContent
     * @return bool
     */
    public static function send(string $toEmail, string $toName, string $subject, string $htmlContent): bool
    {
        $apiKey = env('BREVO_API_KEY');

        if (!$apiKey) {
            Log::error('BrevoMailService: BREVO_API_KEY is not set.');
            return false;
        }

        $fromEmail = env('MAIL_FROM_ADDRESS', 'mannabridalsupport@gmail.com');
        $fromName  = env('MAIL_FROM_NAME', 'Manna Bridal');

        defer(function () use ($apiKey, $fromEmail, $fromName, $toEmail, $toName, $subject, $htmlContent) {
            $response = Http::timeout(10)
                ->withHeaders([
                    'api-key'      => $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])
                ->post(self::API_URL, [
                    'sender'      => ['name' => $fromName, 'email' => $fromEmail],
                    'to'          => [['email' => $toEmail, 'name' => $toName]],
                    'subject'     => $subject,
                    'htmlContent' => $htmlContent,
                ]);

            if (!$response->successful()) {
                Log::error('BrevoMailService: Failed to send email.', [
                    'to'       => $toEmail,
                    'subject'  => $subject,
                    'status'   => $response->status(),
                    'response' => $response->body(),
                ]);
            } else {
                Log::info('BrevoMailService: Email sent.', ['to' => $toEmail, 'subject' => $subject]);
            }
        });

        return true;
    }

    /**
     * Send an OTP verification email.
     */
    public static function sendOtp(string $toEmail, string $toName, string $otp): bool
    {
        $subject = 'Verify your email – Manna Bridal';
        $html = '
<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; background: #f4f4f4; padding: 30px;">
  <div style="max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
    <h2 style="color: #1A1A2E; margin-bottom: 4px;">Verify your email</h2>
    <p style="color: #555; margin-top: 0;">Hello <strong>' . htmlspecialchars($toName) . '</strong>,</p>
    <p style="color: #555;">Thank you for registering at <strong>Manna Bridal</strong>! Use the OTP below to complete your registration. It expires in <strong>15 minutes</strong>.</p>
    <div style="text-align:center; padding:18px 24px; margin:24px 0; font-size:32px; font-weight:bold; letter-spacing:8px; background:#FFF4EC; border:2px dashed #F47B20; border-radius:10px; color:#F47B20;">
      ' . htmlspecialchars($otp) . '
    </div>
    <p style="color:#999; font-size:13px;">If you did not create an account, you can safely ignore this email.</p>
    <hr style="border:none; border-top:1px solid #eee; margin:24px 0;">
    <p style="color:#bbb; font-size:12px; text-align:center;">© ' . date('Y') . ' Manna Bridal. All rights reserved.</p>
  </div>
</body>
</html>';

        return self::send($toEmail, $toName, $subject, $html);
    }

    /**
     * Send a password reset OTP email.
     */
    public static function sendPasswordResetOtp(string $toEmail, string $toName, string $otp): bool
    {
        $subject = 'Password Reset OTP – Manna Bridal';
        $html = '
<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; background: #f4f4f4; padding: 30px;">
  <div style="max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
    <h2 style="color: #1A1A2E;">Password Reset Request</h2>
    <p style="color: #555;">Hello <strong>' . htmlspecialchars($toName) . '</strong>,</p>
    <p style="color: #555;">We received a request to reset your password. Use the OTP below. It expires in <strong>15 minutes</strong>.</p>
    <div style="text-align:center; padding:18px 24px; margin:24px 0; font-size:32px; font-weight:bold; letter-spacing:8px; background:#FFF4EC; border:2px dashed #F47B20; border-radius:10px; color:#F47B20;">
      ' . htmlspecialchars($otp) . '
    </div>
    <p style="color:#999; font-size:13px;">If you did not request this, ignore this email. Your password will not change.</p>
    <hr style="border:none; border-top:1px solid #eee; margin:24px 0;">
    <p style="color:#bbb; font-size:12px; text-align:center;">© ' . date('Y') . ' Manna Bridal. All rights reserved.</p>
  </div>
</body>
</html>';

        return self::send($toEmail, $toName, $subject, $html);
    }

    /**
     * Send a welcome email after successful registration.
     */
    public static function sendWelcome(string $toEmail, string $toName): bool
    {
        $subject = 'Welcome to Manna Bridal!';
        $html = '
<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; background: #f4f4f4; padding: 30px;">
  <div style="max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
    <h2 style="color: #1A1A2E;">Welcome, ' . htmlspecialchars($toName) . '! 🎉</h2>
    <p style="color: #555;">Your account at <strong>Manna Bridal</strong> has been successfully created.</p>
    <p style="color: #555;">Browse our collection and find your perfect bridal look.</p>
    <div style="text-align:center; margin:24px 0;">
      <a href="' . env('FRONTEND_URL', 'https://mannabridal.netlify.app') . '" style="background:#F47B20; color:#fff; padding:12px 28px; border-radius:8px; text-decoration:none; font-weight:bold; display:inline-block;">Shop Now</a>
    </div>
    <hr style="border:none; border-top:1px solid #eee; margin:24px 0;">
    <p style="color:#bbb; font-size:12px; text-align:center;">© ' . date('Y') . ' Manna Bridal. All rights reserved.</p>
  </div>
</body>
</html>';

        return self::send($toEmail, $toName, $subject, $html);
    }
}
