<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\BrevoMailService;

echo "Testing Brevo HTTP API...\n";

$mailer = app(BrevoMailService::class);
$result = $mailer->send(
    ['email' => 'david07israel@gmail.com', 'name' => 'David'],
    'Manna Bridal — API Test ✅',
    '<div style="font-family:Arial,sans-serif;padding:24px;">
        <h2 style="color:#7c3aed;">Email Delivery Working!</h2>
        <p>This email was sent using the <strong>Brevo REST API</strong> — no SMTP required.</p>
        <p>All system emails are now active.</p>
    </div>'
);

echo $result ? "SUCCESS: Email sent via Brevo API!\n" : "FAILED: Check Laravel logs.\n";
