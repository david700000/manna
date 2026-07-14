<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$u = \App\Models\User::where("email", "david07israel@gmail.com")->first();
$token = $u->createToken("test")->plainTextToken;
echo $token;
