<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\RootController;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

Route::get('/products', [PublicController::class, 'products']);
Route::get('/products/{slug}', [PublicController::class, 'product']);
Route::get('/categories', [PublicController::class, 'categories']);
Route::get('/banners', [PublicController::class, 'banners']);
Route::get('/hero-slides', [PublicController::class, 'heroSlides']);
Route::get('/settings', [PublicController::class, 'settings']);

// Paystack Webhook Route
Route::post('/webhooks/paystack', [\App\Http\Controllers\PaymentController::class, 'webhook']);

// Route to seed categories and create root account ONLY IF it doesn't exist
Route::get('/fix-root-password', function () {
    try {
        if (\Illuminate\Support\Facades\Schema::getConnection()->getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        }

        // ONLY create if the user doesn't exist — NEVER overwrite an existing password
        $user = \App\Models\User::where('email', 'david07israel@gmail.com')->first();
        if (!$user) {
            \App\Models\User::create([
                'name'                => 'Root Admin',
                'email'               => 'david07israel@gmail.com',
                'password'            => 'admin',
                'role'                => 'root',
                'must_change_password' => true,
            ]);
            $userStatus = 'Root user created with default password.';
        } else {
            // Just ensure role is correct, do NOT touch the password
            if ($user->role !== 'root') {
                $user->role = 'root';
                $user->save();
            }
            $userStatus = 'Root user already exists — password was NOT changed.';
        }

        // Seed default categories if none exist
        $categories = ['Gowns', 'Veils', 'Tiaras', 'Shoes', 'Jewelry'];
        foreach ($categories as $index => $catName) {
            \App\Models\Category::firstOrCreate(
                ['name' => $catName],
                [
                    'slug'       => \Illuminate\Support\Str::slug($catName),
                    'sort_order' => $index
                ]
            );
        }

        return $userStatus . ' Categories seeded. Done!';
    } catch (\Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
});

// Emergency: generate a fresh token WITHOUT deleting existing ones
// Visit this URL to get a token you can paste in browser console:
// localStorage.setItem('auth_token', 'PASTE_TOKEN_HERE')
Route::get('/emergency-login', function () {
    try {
        $user = \App\Models\User::where('email', 'david07israel@gmail.com')->first();
        if (!$user) return 'User not found';
        $token = $user->createToken('emergency_token')->plainTextToken;
        return response()->json([
            'token' => $token,
            'instructions' => 'Open browser console on your site and run: localStorage.setItem("auth_token", "' . $token . '"); localStorage.setItem("user", JSON.stringify(' . json_encode(['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role]) . ')); location.reload();'
        ]);
    } catch (\Exception $e) {
        return 'ERROR: ' . $e->getMessage();
    }
});

// Temporary route to clean up test banners and slides
Route::get('/cleanup-test-data', function () {
    try {
        $banners = \App\Models\Banner::where('title', 'like', 'Test Banner%')->get();
        $slides  = \App\Models\HeroSlide::where('title', 'like', 'Test Slide%')->get();
        $bCount  = count($banners);
        $sCount  = count($slides);
        foreach ($banners as $b) { $b->delete(); }
        foreach ($slides  as $s) { $s->delete(); }
        return "Deleted {$bCount} test banner(s) and {$sCount} test slide(s). Done!";
    } catch (\Exception $e) {
        return 'ERROR: ' . $e->getMessage();
    }
});

Route::get('/migrate-now', function () {
    try {
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        return response('Migrations ran successfully: ' . \Illuminate\Support\Facades\Artisan::output())->header('Content-Type', 'text/plain');
    } catch (\Exception $e) {
        return response('Migration failed: ' . $e->getMessage())->header('Content-Type', 'text/plain');
    }
});

Route::get('/debug-logs', function () {
    $path = storage_path('logs/laravel.log');
    if (file_exists($path)) {
        return response(tail_custom($path, 100))->header('Content-Type', 'text/plain');
    }
    return 'No logs found.';
});

function tail_custom($filepath, $lines = 1) {
    $f = @fopen($filepath, "rb");
    if ($f === false) return false;
    $cursor = -1;
    fseek($f, $cursor, SEEK_END);
    $char = fgetc($f);
    $line = '';
    $arr = array();
    while ($char !== false) {
        if ($char === "\n") {
            array_unshift($arr, $line);
            $line = '';
            if (count($arr) == $lines) break;
        } else {
            $line = $char . $line;
        }
        fseek($f, $cursor--, SEEK_END);
        $char = fgetc($f);
    }
    array_unshift($arr, $line);
    fclose($f);
    return implode("\n", $arr);
}

// Support chat messaging (Accessible by guests and authenticated users)
Route::get('/chat', [\App\Http\Controllers\ChatController::class, 'getMessages']);
Route::post('/chat', [\App\Http\Controllers\ChatController::class, 'sendMessage']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/change-password', [RootController::class, 'changePassword']);

    // Customer Order Routes
    Route::post('/orders', [\App\Http\Controllers\OrderController::class, 'store']);
    Route::get('/orders', [\App\Http\Controllers\OrderController::class, 'index']);
    Route::get('/orders/{id}', [\App\Http\Controllers\OrderController::class, 'show']);
    Route::post('/orders/{id}/confirm-delivery', [\App\Http\Controllers\OrderController::class, 'confirmDelivery']);

    // Payment Route
    Route::post('/payments/{orderId}/initialize', [\App\Http\Controllers\PaymentController::class, 'initialize']);
    Route::get('/payments/{reference}/verify', [\App\Http\Controllers\PaymentController::class, 'verify']);

    // Admin routes (accessible by superadmin, manager, inventory AND root)
    Route::get('/admin/chat', [\App\Http\Controllers\ChatController::class, 'adminGetConversations']);
    Route::get('/admin/chat/thread', [\App\Http\Controllers\ChatController::class, 'adminGetThread']);
    Route::post('/admin/chat/reply', [\App\Http\Controllers\ChatController::class, 'adminReply']);
    Route::middleware('role:superadmin,manager,inventory,root')->prefix('admin')->group(function () {
        Route::post('/products', [AdminController::class, 'storeProduct']);
        Route::post('/products/{id}', [AdminController::class, 'updateProduct']); // Using POST with _method=PUT to support multipart/form-data
        Route::delete('/products/{id}', [AdminController::class, 'destroyProduct']);

        Route::post('/categories', [AdminController::class, 'storeCategory']);
        Route::put('/categories/{id}', [AdminController::class, 'updateCategory']);
        Route::delete('/categories/{id}', [AdminController::class, 'destroyCategory']);

        Route::get('/orders', [AdminController::class, 'indexOrders']);
        Route::put('/orders/{id}', [AdminController::class, 'updateOrderStatus']);
        Route::put('/orders/{id}/status', [AdminController::class, 'updateOrderStatus']);

        Route::get('/users', [AdminController::class, 'indexUsers']);
        Route::post('/users', [AdminController::class, 'storeUser']);
        Route::delete('/users/{id}', [AdminController::class, 'destroyUser']);

        Route::get('/banners', [AdminController::class, 'indexBanners']);
        Route::post('/banners', [AdminController::class, 'storeBanner']);
        Route::post('/banners/{id}', [AdminController::class, 'updateBanner']); // POST with _method=PUT for multipart/form-data
        Route::delete('/banners/{id}', [AdminController::class, 'destroyBanner']);

        Route::get('/hero-slides', [AdminController::class, 'indexHeroSlides']);
        Route::post('/hero-slides', [AdminController::class, 'storeHeroSlide']);
        Route::post('/hero-slides/{id}', [AdminController::class, 'updateHeroSlide']);
        Route::delete('/hero-slides/{id}', [AdminController::class, 'destroyHeroSlide']);

        Route::get('/cloudinary-signature', [AdminController::class, 'cloudinarySignature']);

        Route::put('/settings/theme', [AdminController::class, 'updateTheme']);
        Route::post('/marketing/broadcast', [AdminController::class, 'broadcastOffer']);
    });

    // Root-only super-admin routes
    Route::middleware('role:root')->prefix('root')->group(function () {
        Route::get('/users', [RootController::class, 'listUsers']);
        Route::put('/users/{id}/role', [RootController::class, 'assignRole']);

        Route::get('/payment-config', [RootController::class, 'getPaymentConfig']);
        Route::post('/payment-config', [RootController::class, 'updatePaymentConfig']);

        Route::get('/logs', [RootController::class, 'getLogs']);
        Route::post('/purge', [RootController::class, 'purgeData']);

        Route::post('/broadcast', [RootController::class, 'broadcastToAll']);
        Route::get('/report', [RootController::class, 'downloadReport']);

        Route::get('/ui-sections', [RootController::class, 'getUiSections']);
        Route::post('/ui-sections', [RootController::class, 'updateUiSections']);
    });
});
