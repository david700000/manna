<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\RootController;

Route::middleware('throttle:5,1')->group(function () {
    Route::post('/auth/send-registration-otp', [AuthController::class, 'sendRegistrationOtp']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
});

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::get('/products', [PublicController::class, 'products']);
Route::get('/products/{slug}', [PublicController::class, 'product']);
Route::get('/categories', [PublicController::class, 'categories']);
Route::get('/banners', [PublicController::class, 'banners']);
Route::get('/hero-slides', [PublicController::class, 'heroSlides']);
Route::get('/settings', [PublicController::class, 'settings']);

// Paystack Webhook Route
Route::post('/webhooks/paystack', [\App\Http\Controllers\PaymentController::class, 'webhook']);


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

Route::get('/debug-payments', function () {
    $payments = \App\Models\Payment::latest()->take(10)->get();
    return response()->json($payments);
});

Route::get('/test-email/{email}', function ($email) {
    $result = \App\Services\BrevoMailService::send(
        $email,
        'Test Admin',
        'Test Email from Manna Bridal',
        '<h2>If you see this, email is working!</h2><p>Sent at: ' . now() . '</p>'
    );
    return response()->json(['queued' => $result, 'brevo_key_set' => !empty(env('BREVO_API_KEY'))]);
});

Route::get('/debug-admins', function () {
    $admins = \App\Models\User::whereNotIn('role', ['customer'])->get(['id', 'name', 'email', 'role']);
    return response()->json($admins);
});

Route::get('/debug-notifs', function () {
    return response()->json(\App\Models\AdminNotification::latest()->take(5)->get());
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
    Route::post('/orders/{id}/rate', [\App\Http\Controllers\OrderController::class, 'rateProduct']);

    // Wishlist Routes
    Route::get('/wishlist', [\App\Http\Controllers\WishlistController::class, 'index']);
    Route::post('/wishlist/toggle', [\App\Http\Controllers\WishlistController::class, 'toggle']);

    // Payments
    Route::post('/payments/{orderId}/initialize', [\App\Http\Controllers\PaymentController::class, 'initialize']);
    Route::post('/orders/{id}/verify-payment', [\App\Http\Controllers\PaymentController::class, 'verifyOrderPayments']);
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
        Route::put('/settings/shipping', [AdminController::class, 'updateShippingSettings']);
        Route::post('/marketing/broadcast', [AdminController::class, 'broadcastOffer']);

        Route::get('/notifications', [AdminController::class, 'getNotifications']);
        Route::post('/notifications/mark-read', [AdminController::class, 'markNotificationsRead']);
    });

    // Root-only super-admin routes
    Route::middleware('role:root')->prefix('root')->group(function () {
        Route::get('/users', [RootController::class, 'getUsers']);
        Route::get('/activity-logs', [RootController::class, 'getActivityLogs']);
        Route::get('/finance-dashboard', [RootController::class, 'getFinanceDashboard']);
        
        Route::post('/payment-auth/request', [RootController::class, 'requestPaymentAuth']);
        Route::post('/payment-auth/verify', [RootController::class, 'verifyPaymentAuth']);
        Route::post('/action-auth/request', [RootController::class, 'requestRootActionAuth']);

        Route::get('/payment-config', [RootController::class, 'getPaymentConfig']);
        Route::post('/payment-config', [RootController::class, 'updatePaymentConfig']);

        Route::post('/reverify-payments', [\App\Http\Controllers\PaymentController::class, 'adminReverifyAll']);

        Route::get('/logs', [RootController::class, 'getLogs']);
        Route::post('/purge', [RootController::class, 'purgeSystem']);
        Route::post('/purge-data', [RootController::class, 'purgeData']);
        Route::post('/purge-users', [RootController::class, 'purgeUsers']);

        Route::post('/broadcast', [RootController::class, 'broadcastToAll']);
        // PDF Reports
        Route::get('/reports/finance', [RootController::class, 'downloadFinanceReport']);
        Route::get('/reports/activity', [RootController::class, 'downloadActivityReport']);
        Route::get('/reports/logs', [RootController::class, 'downloadSystemLogsReport']);
        Route::get('/report', [RootController::class, 'downloadReport']); // original system report

        Route::get('/ui-sections', [RootController::class, 'getUiSections']);
        Route::post('/ui-sections', [RootController::class, 'updateUiSections']);
        
        // Backup routes
        Route::get('/backup/export', [RootController::class, 'exportDatabase']);
        Route::post('/backup/import', [RootController::class, 'importDatabase']);
    });
});
