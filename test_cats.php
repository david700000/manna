<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$cats = \App\Models\Category::all();
echo "COUNT: " . $cats->count() . "\n";
foreach ($cats as $c) {
    echo "ID: {$c->id}, NAME: {$c->name}\n";
}
