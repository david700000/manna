<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo \App\Models\Banner::count() . " banners\n";
echo \App\Models\HeroSlide::count() . " slides\n";
