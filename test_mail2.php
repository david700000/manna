<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $result = app(\App\Services\BrevoMailService::class)->send('mannabridalsupport@gmail.com', 'Test from API', '<p>Hello from REST API!</p>');
    if ($result) {
        echo "SUCCESS: Mail sent to mannabridalsupport@gmail.com via REST API\n";
    } else {
        echo "FAILED: Result was false (check laravel.log)\n";
    }
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
