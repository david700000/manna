<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create('/api/admin/banners', 'POST', ['title' => 'Test', 'status' => 'active']);
$request->headers->set('Accept', 'application/json');
$user = App\Models\User::where('email', 'david07israel@gmail.com')->first();
$request->setUserResolver(function() use ($user) { return $user; });
$response = $kernel->handle($request);
echo $response->getContent();
