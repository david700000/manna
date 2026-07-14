<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Delete all test banners and slides
$banners = \App\Models\Banner::where('title', 'like', 'Test Banner%')->get();
$slides  = \App\Models\HeroSlide::where('title', 'like', 'Test Slide%')->get();

foreach ($banners as $b) { $b->delete(); }
foreach ($slides as $s) { $s->delete(); }

echo "Deleted " . count($banners) . " test banner(s) and " . count($slides) . " test slide(s).\n";
echo "Remaining banners: " . \App\Models\Banner::count() . "\n";
echo "Remaining slides: " . \App\Models\HeroSlide::count() . "\n";
