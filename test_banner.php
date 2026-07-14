<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$req = Illuminate\Http\Request::create("/api/admin/banners", "POST", [
    'title' => 'Test Banner', 
    'status' => 'active'
]);
$ctrl = app(\App\Http\Controllers\AdminController::class);
try {
    $res = $ctrl->storeBanner($req);
    echo $res->getContent();
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
