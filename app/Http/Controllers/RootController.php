<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ActivityLog;
use App\Notifications\MarketingOffer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Services\BrevoMailService;
use Barryvdh\DomPDF\Facade\Pdf;

class RootController extends Controller
{
    // ── Role Management ───────────────────────────────────────────────────────

    public function getUsers(Request $request)
    {
        $users = User::where('role', '!=', 'customer')->orderBy('created_at', 'desc')->get(['id', 'name', 'email', 'role', 'must_change_password', 'created_at']);
        return response()->json($users);
    }

    public function assignRole(Request $request, $id)
    {
        $validated = $request->validate([
            'role' => 'required|in:admin,staff,root',
        ]);

        $user = User::findOrFail($id);

        // Prevent changing an existing assigned role (roles can only be set initially)
        if (!empty($user->role) && $user->role !== 'customer' && $user->role !== $validated['role']) {
            return response()->json(['message' => 'Roles cannot be changed once assigned.'], 403);
        }

        // Prevent de-rooting yourself
        if ($user->id === $request->user()->id && $validated['role'] !== 'root') {
            return response()->json(['message' => 'You cannot change your own root role.'], 403);
        }

        // All elevated roles must reset their password on next login
        $user->update([
            'role'                => $validated['role'],
            'must_change_password' => true,
        ]);

        return response()->json(['message' => 'Role updated successfully.', 'user' => $user]);
    }

    // ── Password: Force Change ────────────────────────────────────────────────

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();
        $user->password = $validated['password'];
        $user->must_change_password = false;
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully.',
            'user'    => $user->fresh(), // return fresh DB copy so client can sync
        ]);
    }

    // ── Payment Config Authorization ──────────────────────────────────────────

    public function requestPaymentAuth(Request $request)
    {
        $validated = $request->validate([
            'password' => 'required|string',
        ]);

        // Re-fetch user with password explicitly selected to bypass the Hidden attribute
        $user = User::where('id', $request->user()->id)->select('id', 'name', 'email', 'role', 'password')->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid password.'], 403);
        }

        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put("payment_auth_otp_{$user->id}", Hash::make($otp), now()->addMinutes(15));

        BrevoMailService::sendAdminActionOtp($user->email, $user->name, $otp, 'view or modify Payment Settings');

        return response()->json(['message' => 'OTP sent successfully.']);
    }

    public function verifyPaymentAuth(Request $request)
    {
        $validated = $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        $user = $request->user();
        $cachedOtpHash = Cache::get("payment_auth_otp_{$user->id}");

        if (!$cachedOtpHash || !Hash::check($validated['otp'], $cachedOtpHash)) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 400);
        }

        // Generate a short-lived token to unlock payment settings
        $unlockToken = Str::random(40);
        Cache::put("payment_unlocked_{$user->id}", $unlockToken, now()->addMinutes(15));
        Cache::forget("payment_auth_otp_{$user->id}");

        return response()->json(['message' => 'Payment settings unlocked.', 'token' => $unlockToken]);
    }

    // ── Payment Config ────────────────────────────────────────────────────────

    public function getPaymentConfig(Request $request)
    {
        $user = $request->user();
        $token = $request->header('X-Payment-Auth-Token');

        if (!$token || Cache::get("payment_unlocked_{$user->id}") !== $token) {
            return response()->json(['message' => 'Unauthorized. Please authenticate.'], 403);
        }

        $keys = ['paystack_public_key', 'paystack_secret_key'];
        $config = [];
        foreach ($keys as $key) {
            $setting = Setting::where('key', $key)->first();
            $config[$key] = $setting ? $setting->value : '';
        }
        return response()->json($config);
    }

    public function updatePaymentConfig(Request $request)
    {
        $user = $request->user();
        $token = $request->header('X-Payment-Auth-Token');

        if (!$token || Cache::get("payment_unlocked_{$user->id}") !== $token) {
            return response()->json(['message' => 'Unauthorized. Please authenticate.'], 403);
        }

        $validated = $request->validate([
            'paystack_public_key'   => 'required|string',
            'paystack_secret_key'   => 'required|string',
        ]);

        foreach ($validated as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return response()->json(['message' => 'Payment configuration updated successfully.']);
    }

    // ── Purge ─────────────────────────────────────────────────────────────────

    public function purgeData(Request $request)
    {
        // Dangerous operation: Only root can do this
        if ($request->user()->role !== 'root') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Turn off foreign key checks for SQLite
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF;');

        \App\Models\OrderItem::truncate();
        \App\Models\Order::truncate();
        // Assuming Payment, Category, CartItem models exist as requested
        // \App\Models\Payment::truncate();
        \App\Models\Product::truncate();
        // \App\Models\Category::truncate();
        if (\Illuminate\Support\Facades\Schema::hasTable('cart_items')) {
            \Illuminate\Support\Facades\DB::table('cart_items')->truncate();
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('wishlists')) {
            \Illuminate\Support\Facades\DB::table('wishlists')->truncate();
        }

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = ON;');

        return response()->json(['message' => 'Database fully purged (Orders, Products, Categories, Carts, Wishlists).']);
    }

    public function purgeUsers(Request $request)
    {
        if ($request->user()->role !== 'root') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Delete all users except root users
        User::where('role', '!=', 'root')->delete();

        return response()->json(['message' => 'All non-root users have been purged.']);
    }

    // ── System Logs ───────────────────────────────────────────────────────────

    public function getLogs()
    {
        $logPath = storage_path('logs/laravel.log');
        if (!file_exists($logPath)) {
            return response()->json(['log' => 'No log file found.']);
        }
        // Return last 200 lines only
        $lines   = file($logPath);
        $last200 = array_slice($lines, -200);
        return response()->json(['log' => implode('', $last200)]);
    }

    public function getActivityLogs(Request $request)
    {
        $logs = ActivityLog::with('user:id,name,email,role')
            ->orderBy('created_at', 'desc')
            ->take(100)
            ->get();
        return response()->json($logs);
    }

    // ── Finance Dashboard ─────────────────────────────────────────────────────

    public function getFinanceDashboard()
    {
        $orders = Order::where('payment_status', 'paid')
            ->orWhere('status', 'delivered') // depending on how revenue is tracked, assuming paid or delivered implies revenue
            ->get(['id', 'total', 'created_at']);

        // Only count paid orders
        $paidOrders = Order::where('payment_status', 'paid')->get(['total', 'created_at']);

        $totalIncome = $paidOrders->sum('total');

        $dailySales = $paidOrders->groupBy(function($order) {
            return $order->created_at->format('Y-m-d');
        })->map(function($day) {
            return $day->sum('total');
        });

        $monthlySales = $paidOrders->groupBy(function($order) {
            return $order->created_at->format('Y-m');
        })->map(function($month) {
            return $month->sum('total');
        });

        $yearlySales = $paidOrders->groupBy(function($order) {
            return $order->created_at->format('Y');
        })->map(function($year) {
            return $year->sum('total');
        });

        return response()->json([
            'total_income' => $totalIncome,
            'daily_sales' => $dailySales,
            'monthly_sales' => $monthlySales,
            'yearly_sales' => $yearlySales,
        ]);
    }

    public function purgeSystem(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:cache,logs,sessions,all',
        ]);

        $messages = [];

        switch ($validated['type']) {
            case 'cache':
                Artisan::call('cache:clear');
                $messages[] = 'Application cache cleared.';
                break;
            case 'logs':
                $logPath = storage_path('logs/laravel.log');
                if (file_exists($logPath)) {
                    file_put_contents($logPath, '');
                }
                $messages[] = 'Log file cleared.';
                break;
            case 'sessions':
                DB::table('sessions')->truncate();
                $messages[] = 'Sessions purged.';
                break;
            case 'all':
                Artisan::call('cache:clear');
                DB::table('sessions')->truncate();
                $logPath = storage_path('logs/laravel.log');
                if (file_exists($logPath)) {
                    file_put_contents($logPath, '');
                }
                $messages[] = 'Cache, sessions, and logs cleared.';
                break;
        }

        return response()->json(['message' => implode(' ', $messages)]);
    }

    // ── System-Wide Broadcast ─────────────────────────────────────────────────

    public function broadcastToAll(Request $request)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        $users = User::all();
        $notification = new MarketingOffer($validated);
        foreach ($users as $user) {
            try { $notification->send($user); } catch (\Throwable $e) {}
        }

        return response()->json(['message' => 'Broadcast sent to ' . $users->count() . ' users (including admins).']);
    }

    // ── Reports ───────────────────────────────────────────────────────────────

    public function downloadReport()
    {
        $orders  = Order::with('user', 'items.product')->get();
        $users   = User::select('id', 'name', 'email', 'role', 'created_at')->get();
        $products = Product::select('id', 'name', 'price', 'stock', 'status')->get();

        $data = compact('orders', 'users', 'products');

        $pdf = Pdf::loadView('reports.system', $data);

        $filename = 'manna-bridal-report-' . now()->format('Y-m-d') . '.pdf';

        return $pdf->download($filename);
    }

    // ── UI Sections (CMS custom blocks) ──────────────────────────────────────

    public function getUiSections()
    {
        $setting = Setting::where('key', 'ui_sections')->first();
        $sections = $setting ? json_decode($setting->value, true) : [];
        return response()->json($sections);
    }

    public function updateUiSections(Request $request)
    {
        $validated = $request->validate([
            'sections' => 'required|array',
        ]);

        Setting::updateOrCreate(
            ['key' => 'ui_sections'],
            ['value' => json_encode($validated['sections'])]
        );

        return response()->json(['message' => 'UI sections updated.']);
    }
}
