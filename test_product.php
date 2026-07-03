<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$req = Illuminate\Http\Request::create('/api/admin/products', 'POST', [
    'name' => 'Test',
    'category' => 'Gowns',
    'price' => 100,
    'stock' => 10,
    'status' => 'active',
    'desc' => 'Desc'
]);
$req->headers->set('Accept', 'application/json');

$user = \App\Models\User::first();
if ($user) {
    auth()->login($user);
}

$res = app()->handle($req);
dump($res->getStatusCode());
dump($res->getContent());
