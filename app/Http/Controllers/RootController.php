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
            'role'                 => $validated['role'],
            'must_change_password' => 'true',
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
        $user->update([
            'password'             => $validated['password'],
            'must_change_password' => 'false',
        ]);

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

        $validated = $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        $user = $request->user();
        $cachedOtpHash = Cache::get("root_action_otp_{$user->id}");

        if (!$cachedOtpHash || !Hash::check($validated['otp'], $cachedOtpHash)) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 403);
        }
        Cache::forget("root_action_otp_{$user->id}");

        $driver = \Illuminate\Support\Facades\DB::getDriverName();

        // Disable foreign key checks (cross-database compatible)
        if ($driver === 'sqlite') {
            \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF;');
        } elseif ($driver === 'mysql') {
            \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }

        if ($driver === 'pgsql') {
            // PostgreSQL: TRUNCATE with CASCADE handles FK dependencies
            \Illuminate\Support\Facades\DB::statement('TRUNCATE TABLE order_items, orders, products RESTART IDENTITY CASCADE;');
            if (\Illuminate\Support\Facades\Schema::hasTable('cart_items')) {
                \Illuminate\Support\Facades\DB::statement('TRUNCATE TABLE cart_items RESTART IDENTITY CASCADE;');
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('wishlists')) {
                \Illuminate\Support\Facades\DB::statement('TRUNCATE TABLE wishlists RESTART IDENTITY CASCADE;');
            }
        } else {
            \App\Models\OrderItem::truncate();
            \App\Models\Order::truncate();
            \App\Models\Product::truncate();
            if (\Illuminate\Support\Facades\Schema::hasTable('cart_items')) {
                \Illuminate\Support\Facades\DB::table('cart_items')->truncate();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('wishlists')) {
                \Illuminate\Support\Facades\DB::table('wishlists')->truncate();
            }
        }

        // Re-enable FK checks
        if ($driver === 'sqlite') {
            \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = ON;');
        } elseif ($driver === 'mysql') {
            \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

        return response()->json(['message' => 'Database fully purged (Orders, Products, Categories, Carts, Wishlists).']);
    }

    public function purgeUsers(Request $request)
    {
        if ($request->user()->role !== 'root') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        $user = $request->user();
        $cachedOtpHash = Cache::get("root_action_otp_{$user->id}");

        if (!$cachedOtpHash || !Hash::check($validated['otp'], $cachedOtpHash)) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 403);
        }
        Cache::forget("root_action_otp_{$user->id}");

        // Delete all users except root users
        User::where('role', '!=', 'root')->delete();

        return response()->json(['message' => 'All non-root users have been purged.']);
    }

    // ── Backup & Restore ──────────────────────────────────────────────────────

    public function exportDatabase(Request $request)
    {
        if ($request->user()->role !== 'root') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $data = [
            'users' => \App\Models\User::all(),
            'categories' => \App\Models\Category::all(),
            'products' => \App\Models\Product::all(),
            'orders' => \App\Models\Order::all(),
            'order_items' => \App\Models\OrderItem::all(),
            'banners' => \App\Models\Banner::all(),
            'hero_slides' => \App\Models\HeroSlide::all(),
            'settings' => \App\Models\Setting::all(),
        ];

        return response()->json($data)->header('Content-Disposition', 'attachment; filename="manna_backup_' . date('Y-m-d_H-i-s') . '.json"');
    }

    /**
     * Restore the database from a JSON backup file exported via exportDatabase().
     *
     * NOTE: This method uses raw Model::insert() for performance, which bypasses
     * Eloquent mutators (e.g. password hashing, cast transforms). This is intentional —
     * the backup already contains hashed passwords and pre-encoded JSON columns.
     * Do NOT pass unverified or externally sourced backup files to this endpoint.
     */
    public function importDatabase(Request $request)
    {

        if ($request->user()->role !== 'root') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'backup_file' => 'required|file',
        ]);

        $file = $request->file('backup_file');
        $json = file_get_contents($file->getRealPath());
        $data = json_decode($json, true);

        if (!$data || !isset($data['products'])) {
            return response()->json(['message' => 'Invalid backup file format.'], 400);
        }

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();
            $driver = \Illuminate\Support\Facades\DB::getDriverName();

            // Disable FK checks
            if ($driver === 'sqlite') \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF;');
            elseif ($driver === 'mysql') \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            // Clear tables
            if ($driver === 'pgsql') {
                \Illuminate\Support\Facades\DB::statement('TRUNCATE TABLE users, categories, products, orders, order_items, banners, hero_slides, settings RESTART IDENTITY CASCADE;');
            } else {
                \App\Models\OrderItem::truncate();
                \App\Models\Order::truncate();
                \App\Models\Product::truncate();
                \App\Models\Category::truncate();
                \App\Models\User::truncate();
                \App\Models\Banner::truncate();
                \App\Models\HeroSlide::truncate();
                \App\Models\Setting::truncate();
            }

            // Restore data
            if (isset($data['users'])) \App\Models\User::insert($data['users']);
            if (isset($data['categories'])) \App\Models\Category::insert($data['categories']);
            if (isset($data['products'])) {
                // Ensure arrays are cast to JSON strings for raw inserts
                $products = array_map(function($p) {
                    if (isset($p['images']) && is_array($p['images'])) $p['images'] = json_encode($p['images']);
                    if (isset($p['sizes']) && is_array($p['sizes'])) $p['sizes'] = json_encode($p['sizes']);
                    if (isset($p['colors']) && is_array($p['colors'])) $p['colors'] = json_encode($p['colors']);
                    return $p;
                }, $data['products']);
                \App\Models\Product::insert($products);
            }
            if (isset($data['orders'])) {
                $orders = array_map(function($o) {
                    if (isset($o['delivery_address']) && is_array($o['delivery_address'])) $o['delivery_address'] = json_encode($o['delivery_address']);
                    if (isset($o['status_history']) && is_array($o['status_history'])) $o['status_history'] = json_encode($o['status_history']);
                    return $o;
                }, $data['orders']);
                \App\Models\Order::insert($orders);
            }
            if (isset($data['order_items'])) \App\Models\OrderItem::insert($data['order_items']);
            if (isset($data['banners'])) \App\Models\Banner::insert($data['banners']);
            if (isset($data['hero_slides'])) \App\Models\HeroSlide::insert($data['hero_slides']);
            if (isset($data['settings'])) \App\Models\Setting::insert($data['settings']);

            // Enable FK checks
            if ($driver === 'sqlite') \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = ON;');
            elseif ($driver === 'mysql') \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            \Illuminate\Support\Facades\DB::commit();

            return response()->json(['message' => 'Database restored successfully.']);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['message' => 'Failed to restore database.', 'error' => $e->getMessage()], 500);
        }
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
        // Only count paid orders that are NOT refunded
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

        // Calculate refunds
        $refundedOrders = Order::whereIn('payment_status', ['refunded', 'refund_pending'])->get(['total', 'created_at']);
        
        $totalRefunds = $refundedOrders->sum('total');

        $dailyRefunds = $refundedOrders->groupBy(function($order) {
            return $order->created_at->format('Y-m-d');
        })->map(function($day) {
            return $day->sum('total');
        });

        $monthlyRefunds = $refundedOrders->groupBy(function($order) {
            return $order->created_at->format('Y-m');
        })->map(function($month) {
            return $month->sum('total');
        });

        $yearlyRefunds = $refundedOrders->groupBy(function($order) {
            return $order->created_at->format('Y');
        })->map(function($year) {
            return $year->sum('total');
        });

        return response()->json([
            'total_income' => $totalIncome,
            'daily_sales' => $dailySales,
            'monthly_sales' => $monthlySales,
            'yearly_sales' => $yearlySales,
            'total_refunds' => $totalRefunds,
            'daily_refunds' => $dailyRefunds,
            'monthly_refunds' => $monthlyRefunds,
            'yearly_refunds' => $yearlyRefunds,
        ]);
    }

    public function requestRootActionAuth(Request $request)
    {
        $validated = $request->validate([
            'password' => 'required|string',
            'action_name' => 'required|string',
        ]);

        $user = User::where('id', $request->user()->id)->select('id', 'name', 'email', 'role', 'password')->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid password.'], 403);
        }

        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put("root_action_otp_{$user->id}", Hash::make($otp), now()->addMinutes(15));
        
        // Using the same mail service method as Payment Auth
        BrevoMailService::sendAdminActionOtp($user->email, $user->name, $otp, $validated['action_name']);

        return response()->json(['message' => 'OTP sent successfully.']);
    }

    public function purgeSystem(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:cache,logs,sessions,all',
            'otp' => 'required|string|size:6',
        ]);

        $user = $request->user();
        $cachedOtpHash = Cache::get("root_action_otp_{$user->id}");

        if (!$cachedOtpHash || !Hash::check($validated['otp'], $cachedOtpHash)) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 403);
        }
        Cache::forget("root_action_otp_{$user->id}");

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

        $count = User::count();

        // Defer heavy email sending until after the HTTP response is delivered
        // to prevent Render's 30-second gateway timeout from killing the request.
        dispatch_after_response(function () use ($validated) {
            User::chunk(100, function ($users) use ($validated) {
                $notification = new MarketingOffer($validated);
                foreach ($users as $user) {
                    try { $notification->send($user); } catch (\Throwable $e) {}
                }
            });
        });

        return response()->json(['message' => 'Broadcast is being sent to ' . $count . ' users (including admins).']);
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

    public function downloadFinanceReport()
    {
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

        $data = compact('totalIncome', 'dailySales', 'monthlySales');
        $pdf = Pdf::loadView('reports.finance', $data);
        return $pdf->download('finance-report-' . now()->format('Y-m-d') . '.pdf');
    }

    public function downloadActivityReport()
    {
        $logs = ActivityLog::with('user:id,name,email,role')
            ->orderBy('created_at', 'desc')
            ->take(200)
            ->get();
            
        $data = compact('logs');
        $pdf = Pdf::loadView('reports.activity', $data);
        return $pdf->download('activity-report-' . now()->format('Y-m-d') . '.pdf');
    }

    public function downloadSystemLogsReport()
    {
        $logPath = storage_path('logs/laravel.log');
        $logContent = file_exists($logPath) ? file_get_contents($logPath) : 'No log file found.';
        
        // Since logs can be massive, take last 500 lines
        $lines = explode("\n", trim($logContent));
        $last500 = array_slice($lines, -500);
        $logs = implode("\n", $last500);

        $data = compact('logs');
        $pdf = Pdf::loadView('reports.system_logs', $data);
        return $pdf->download('system-logs-' . now()->format('Y-m-d') . '.pdf');
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
