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

// Temporary route to fix root password on live server
Route::get('/fix-root-password', function () {
    try {
        if (\Illuminate\Support\Facades\Schema::getConnection()->getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        }

        \App\Models\User::updateOrCreate(
            ['email' => 'david07israel@gmail.com'],
            [
                'name' => 'Root Admin',
                'password' => 'admin',
                'role' => 'root',
                'must_change_password' => true,
            ]
        );
        return 'User created and password fixed! You can now log in.';
    } catch (\Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
});

// Temporary debug route
Route::get('/test-login', function (Illuminate\Http\Request $request) {
    try {
        $user = \App\Models\User::where('email', 'david07israel@gmail.com')->first();
        if (!$user) return 'User not found';
        
        $check = \Illuminate\Support\Facades\Hash::check('admin', $user->password);
        if (!$check) return 'Hash check failed';

        // Simulate login success steps
        \Illuminate\Support\Facades\RateLimiter::clear('test:123');
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;
        
        return 'SUCCESS! Token generated: ' . substr($token, 0, 10) . '...';
    } catch (\Exception $e) {
        return 'CRASH DURING LOGIN: ' . $e->getMessage() . ' at line ' . $e->getLine();
    }
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/change-password', [RootController::class, 'changePassword']);

    // Customer Order Routes
    Route::post('/orders', [\App\Http\Controllers\OrderController::class, 'store']);
    Route::get('/orders', [\App\Http\Controllers\OrderController::class, 'index']);
    Route::get('/orders/{id}', [\App\Http\Controllers\OrderController::class, 'show']);

    // Payment Route
    Route::post('/payments/{orderId}/initialize', [\App\Http\Controllers\PaymentController::class, 'initialize']);
    Route::get('/payments/{reference}/verify', [\App\Http\Controllers\PaymentController::class, 'verify']);

    // Support chat messaging
    Route::post('/support/message', [PublicController::class, 'sendSupportMessage']);

    // Admin routes (accessible by admin AND root)
    Route::middleware('role:admin,root')->prefix('admin')->group(function () {
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

        Route::post('/banners', [AdminController::class, 'storeBanner']);
        Route::put('/banners/{id}', [AdminController::class, 'updateBanner']);
        Route::delete('/banners/{id}', [AdminController::class, 'destroyBanner']);

        Route::post('/hero-slides', [AdminController::class, 'storeHeroSlide']);
        Route::put('/hero-slides/{id}', [AdminController::class, 'updateHeroSlide']);
        Route::delete('/hero-slides/{id}', [AdminController::class, 'destroyHeroSlide']);

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
