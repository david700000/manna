<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$order = \App\Models\Order::first();
if (!$order) { echo "No orders"; exit; }

$req = Illuminate\Http\Request::create("/api/payments/{$order->id}/initialize", "POST");
$req->setUserResolver(function() { return \App\Models\User::first(); });

$ctrl = app(\App\Http\Controllers\PaymentController::class);
$res = $ctrl->initialize($req, $order->id);
echo $res->getContent();
