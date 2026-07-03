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
use App\Notifications\MarketingOffer;

class RootController extends Controller
{
    // ── Role Management ───────────────────────────────────────────────────────

    public function listUsers()
    {
        $users = User::orderBy('created_at', 'desc')->get(['id', 'name', 'email', 'role', 'must_change_password', 'created_at']);
        return response()->json($users);
    }

    public function assignRole(Request $request, $id)
    {
        $validated = $request->validate([
            'role' => 'required|in:admin,staff,customer,root',
        ]);

        $user = User::findOrFail($id);

        // Prevent de-rooting yourself
        if ($user->id === $request->user()->id && $validated['role'] !== 'root') {
            return response()->json(['message' => 'You cannot change your own root role.'], 403);
        }

        // All elevated roles must reset their password on next login
        $elevatedRoles = ['admin', 'staff', 'root'];
        $mustChangePwd = in_array($validated['role'], $elevatedRoles);

        $user->update([
            'role' => $validated['role'],
            'must_change_password' => $mustChangePwd,
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

    // ── Payment Config ────────────────────────────────────────────────────────

    public function getPaymentConfig()
    {
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
        $validated = $request->validate([
            'paystack_public_key'   => 'required|string',
            'paystack_secret_key'   => 'required|string',
        ]);

        foreach ($validated as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return response()->json(['message' => 'Payment configuration updated successfully.']);
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

    // ── Data Purge ────────────────────────────────────────────────────────────

    public function purgeData(Request $request)
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

        $csvRows = [];

        // ── Orders section
        $csvRows[] = ['=== ORDERS REPORT ==='];
        $csvRows[] = ['Order ID', 'Reference', 'Customer', 'Email', 'Status', 'Payment Status', 'Total (₦)', 'Items', 'Date'];
        foreach ($orders as $order) {
            $itemNames = $order->items->map(fn($i) => $i->product?->name . ' x' . $i->quantity)->join('; ');
            $csvRows[] = [
                $order->id,
                $order->reference,
                $order->user?->name ?? 'N/A',
                $order->user?->email ?? 'N/A',
                $order->status,
                $order->payment_status,
                number_format($order->total_amount, 2),
                $itemNames,
                $order->created_at,
            ];
        }

        $csvRows[] = [];

        // ── Users section
        $csvRows[] = ['=== USERS REPORT ==='];
        $csvRows[] = ['User ID', 'Name', 'Email', 'Role', 'Joined'];
        foreach ($users as $user) {
            $csvRows[] = [$user->id, $user->name, $user->email, $user->role, $user->created_at];
        }

        $csvRows[] = [];

        // ── Products section
        $csvRows[] = ['=== PRODUCTS REPORT ==='];
        $csvRows[] = ['Product ID', 'Name', 'Price (₦)', 'Stock', 'Status'];
        foreach ($products as $product) {
            $csvRows[] = [$product->id, $product->name, number_format($product->price, 2), $product->stock, $product->status];
        }

        $csvContent = '';
        foreach ($csvRows as $row) {
            $csvContent .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $row)) . "\n";
        }

        $filename = 'manna-bridal-report-' . now()->format('Y-m-d') . '.csv';

        return response($csvContent, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
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
