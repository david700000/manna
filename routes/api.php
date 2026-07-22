<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\RootController;

Route::post('/auth/send-registration-otp', [AuthController::class, 'sendRegistrationOtp']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

Route::get('/products', [PublicController::class, 'products']);
Route::get('/products/{slug}', [PublicController::class, 'product']);
Route::get('/categories', [PublicController::class, 'categories']);
Route::get('/banners', [PublicController::class, 'banners']);
Route::get('/hero-slides', [PublicController::class, 'heroSlides']);
Route::get('/settings', [PublicController::class, 'settings']);

// Paystack Webhook Route
Route::post('/webhooks/paystack', [\App\Http\Controllers\PaymentController::class, 'webhook']);

// Test all email services — fires one of each email to ADMIN_EMAIL
Route::get('/test-emails', function () {
    $adminEmail = env('ADMIN_EMAIL', 'mannabridalsupport@gmail.com');
    $fakeUser   = (object)['name' => 'Test User', 'email' => $adminEmail, 'role' => 'manager'];
    $results    = [];

    // 1. Welcome email
    try {
        (new \App\Notifications\WelcomeUser())->send($fakeUser);
        $results['welcome'] = 'sent';
    } catch (\Throwable $e) { $results['welcome'] = 'FAILED: ' . $e->getMessage(); }

    // 2. Admin invitation
    try {
        (new \App\Notifications\AdminInvitationNotification('Temp@12345'))->send($fakeUser);
        $results['admin_invite'] = 'sent';
    } catch (\Throwable $e) { $results['admin_invite'] = 'FAILED: ' . $e->getMessage(); }

    // 3. OTP reset
    try {
        (new \App\Notifications\PasswordResetNotification('847291', true))->send($fakeUser);
        $results['otp_reset'] = 'sent';
    } catch (\Throwable $e) { $results['otp_reset'] = 'FAILED: ' . $e->getMessage(); }

    // 4. Order status (use latest real order if exists, else a fake)
    try {
        $order = \App\Models\Order::latest()->first();
        if ($order) {
            (new \App\Notifications\OrderStatusUpdate($order))->send($fakeUser);
            $results['order_status'] = 'sent (order: ' . $order->reference . ')';
        } else {
            $results['order_status'] = 'skipped (no orders exist yet)';
        }
    } catch (\Throwable $e) { $results['order_status'] = 'FAILED: ' . $e->getMessage(); }

    // 5. Order placed admin notification
    try {
        $order = \App\Models\Order::latest()->first();
        if ($order) {
            (new \App\Notifications\OrderPlacedAdminNotification($order))->send($fakeUser);
            $results['order_placed_admin'] = 'sent';
        } else {
            $results['order_placed_admin'] = 'skipped (no orders exist yet)';
        }
    } catch (\Throwable $e) { $results['order_placed_admin'] = 'FAILED: ' . $e->getMessage(); }

    return response()->json([
        'message' => 'Email test complete. Check ' . $adminEmail . ' inbox.',
        'results' => $results,
    ]);
});

// Diagnostic: test OTP email + cache independently
Route::get('/test-otp-email', function () {
    $adminEmail = env('ADMIN_EMAIL', 'mannabridalsupport@gmail.com');
    $results = [];

    // Test cache
    try {
        \Illuminate\Support\Facades\Cache::put('test_otp_diag', '999888', 60);
        $val = \Illuminate\Support\Facades\Cache::get('test_otp_diag');
        $results['cache'] = ($val === '999888') ? 'OK' : 'FAILED: got ' . $val;
    } catch (\Throwable $e) {
        $results['cache'] = 'FAILED: ' . $e->getMessage();
    }

    // Test OTP notification email
    try {
        \Illuminate\Support\Facades\Notification::route('mail', $adminEmail)
            ->notify(new \App\Notifications\RegistrationOtpNotification('123456', 'Test User'));
        $results['otp_email'] = 'sent to ' . $adminEmail;
    } catch (\Throwable $e) {
        $results['otp_email'] = 'FAILED: ' . $e->getMessage();
    }

    return response()->json(['results' => $results]);
});




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

if (!function_exists('tail_custom')) {
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
    Route::get('/orders', [\App\Http\Controllers\OrderController::class, 'index']);
    Route::post('/orders', [\App\Http\Controllers\OrderController::class, 'store']);
    Route::get('/orders/{id}', [\App\Http\Controllers\OrderController::class, 'show']);
    Route::post('/orders/{id}/confirm-delivery', [\App\Http\Controllers\OrderController::class, 'confirmDelivery']);
    Route::post('/orders/{id}/cancel', [\App\Http\Controllers\OrderController::class, 'cancelOrder']);

    // Wishlist Routes
    Route::get('/wishlist', [\App\Http\Controllers\WishlistController::class, 'index']);
    Route::post('/wishlist/toggle', [\App\Http\Controllers\WishlistController::class, 'toggle']);

    // Payment Route
    Route::post('/payments/{orderId}/initialize', [\App\Http\Controllers\PaymentController::class, 'initialize']);
    Route::get('/payments/{reference}/verify', [\App\Http\Controllers\PaymentController::class, 'verify']);

    // Customer Cloudinary Upload Signature (for inquiry/chat image uploads)
    Route::get('/customer/cloudinary-signature', [AdminController::class, 'customerCloudinarySignature']);

    // Admin routes (accessible by superadmin, manager, inventory AND root)
    Route::get('/admin/chat', [\App\Http\Controllers\ChatController::class, 'adminGetConversations']);
    Route::get('/admin/chat/thread', [\App\Http\Controllers\ChatController::class, 'adminGetThread']);
    Route::post('/admin/chat/reply', [\App\Http\Controllers\ChatController::class, 'adminReply']);
    Route::middleware('role:superadmin,manager,inventory,staff,root')->prefix('admin')->group(function () {
        Route::get('/products', [AdminController::class, 'indexProducts']);
        Route::post('/products', [AdminController::class, 'storeProduct']);
        Route::put('/products/{id}', [AdminController::class, 'updateProduct']); // Uses POST with _method=PUT from frontend
        Route::delete('/products/{id}', [AdminController::class, 'destroyProduct']);

        Route::post('/categories', [AdminController::class, 'storeCategory']);
        Route::put('/categories/{id}', [AdminController::class, 'updateCategory']);
        Route::delete('/categories/{id}', [AdminController::class, 'destroyCategory']);

        Route::get('/orders', [AdminController::class, 'indexOrders']);
        Route::put('/orders/{id}', [AdminController::class, 'updateOrderStatus']);
        Route::put('/orders/{id}/status', [AdminController::class, 'updateOrderStatus']);
        Route::delete('/orders', [AdminController::class, 'purgeOrders']);

        Route::get('/customers', [AdminController::class, 'indexCustomers']);
        Route::get('/users', [AdminController::class, 'indexUsers']);
        Route::post('/users', [AdminController::class, 'storeUser']);
        Route::delete('/users/{id}', [AdminController::class, 'destroyUser']);

        Route::get('/banners', [AdminController::class, 'indexBanners']);
        Route::post('/banners', [AdminController::class, 'storeBanner']);
        Route::put('/banners/{id}', [AdminController::class, 'updateBanner']);
        Route::delete('/banners/{id}', [AdminController::class, 'destroyBanner']);

        Route::get('/hero-slides', [AdminController::class, 'indexHeroSlides']);
        Route::post('/hero-slides', [AdminController::class, 'storeHeroSlide']);
        Route::put('/hero-slides/{id}', [AdminController::class, 'updateHeroSlide']);
        Route::delete('/hero-slides/{id}', [AdminController::class, 'destroyHeroSlide']);

        Route::get('/cloudinary-signature', [AdminController::class, 'cloudinarySignature']);

        Route::put('/settings/theme', [AdminController::class, 'updateTheme']);
        Route::post('/marketing/broadcast', [AdminController::class, 'broadcastOffer']);
    });

    // Root-only super-admin routes
    Route::middleware('role:root')->prefix('root')->group(function () {
        Route::get('/users', [RootController::class, 'getUsers']);
        Route::get('/activity-logs', [RootController::class, 'getActivityLogs']);
        Route::get('/finance-dashboard', [RootController::class, 'getFinanceDashboard']);
        
        Route::post('/payment-auth/request', [RootController::class, 'requestPaymentAuth']);
        Route::post('/payment-auth/verify', [RootController::class, 'verifyPaymentAuth']);

        Route::get('/payment-config', [RootController::class, 'getPaymentConfig']);
        Route::post('/payment-config', [RootController::class, 'updatePaymentConfig']);

        Route::post('/reverify-payments', [\App\Http\Controllers\PaymentController::class, 'adminReverifyAll']);

        Route::get('/logs', [RootController::class, 'getLogs']);
        Route::post('/purge', [RootController::class, 'purgeSystem']);
        Route::post('/purge-data', [RootController::class, 'purgeData']);
        Route::post('/purge-users', [RootController::class, 'purgeUsers']);

        Route::post('/broadcast', [RootController::class, 'broadcastToAll']);
        Route::get('/report', [RootController::class, 'downloadReport']);

        Route::get('/ui-sections', [RootController::class, 'getUiSections']);
        Route::post('/ui-sections', [RootController::class, 'updateUiSections']);
    });
});
