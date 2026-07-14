<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$req = Illuminate\Http\Request::create("/api/admin/hero-slides", "POST", ["title" => "Test Slide"]);
$ctrl = app(\App\Http\Controllers\AdminController::class);
try {
    $res = $ctrl->storeHeroSlide($req);
    echo $res->getContent();
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
