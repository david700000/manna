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

    // Admin routes (accessible by superadmin, manager, inventory AND root)
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
        Route::post('/hero-slides/{id}', [AdminController::class, 'updateHeroSlide']); // POST with _method=PUT for multipart/form-data
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
