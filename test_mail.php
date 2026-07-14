<?php
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Mail;

try {
    Mail::raw('This is a test email from Manna Bridal SMTP. If you receive this, email is working correctly.', function ($m) {
        $m->to('mannabridalsupport@gmail.com')
          ->subject('[TEST] Manna Bridal Email Working ✅');
    });
    echo "SUCCESS: Mail sent to mannabridalsupport@gmail.com\n";
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
