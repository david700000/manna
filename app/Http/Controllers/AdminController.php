<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Category;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Notification;
use App\Notifications\OrderStatusUpdate;
use App\Notifications\MarketingOffer;
use App\Models\ActivityLog;

class AdminController extends Controller
{
    public function __construct()
    {
        if (env('CLOUDINARY_URL')) {
            \Cloudinary\Configuration\Configuration::instance(env('CLOUDINARY_URL'));
        }
    }

    private function uploadImage($file, $folder)
    {
        if (env('CLOUDINARY_URL')) {
            $upload = new \Cloudinary\Api\Upload\UploadApi();
            $result = $upload->upload($file->getRealPath(), ['folder' => $folder]);
            return $result['secure_url'];
        }
        // Fallback to local storage
        $path = $file->store($folder, 'public');
        return '/storage/' . $path;
    }

    public function indexProducts()
    {
        return response()->json(Product::with('category')->get());
    }

    public function storeProduct(Request $request)
    {
        if ($request->has('sizes') && is_string($request->input('sizes'))) {
            $request->merge(['sizes' => json_decode($request->input('sizes'), true) ?? []]);
        }
        if ($request->has('colors') && is_string($request->input('colors'))) {
            $request->merge(['colors' => json_decode($request->input('colors'), true) ?? []]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric',
            'original_price' => 'nullable|numeric',
            'stock' => 'required|integer',
            'status' => 'required|in:active,draft,archived',
            'category_id' => 'nullable|exists:categories,id',
            'existing_images' => 'nullable|array',
            'sizes' => 'nullable|array',
            'sizes.*' => 'string|max:20',
            'colors' => 'nullable|array',
            'colors.*' => 'string|max:50',
            'is_free_shipping' => 'nullable|string',
        ]);

        // Validate uploaded image files separately (field name is 'images' from multipart images[])
        if ($request->hasFile('images')) {
            $request->validate(['images.*' => 'image|max:10240']);
        }

        $imagePaths = $request->input('existing_images', []);
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $imagePaths[] = $this->uploadImage($image, 'products');
            }
        }

        $validated['slug'] = Str::slug($validated['name']) . '-' . uniqid();
        $validated['images'] = $imagePaths;
        $validated['sizes'] = $request->input('sizes', []);
        $validated['colors'] = $request->input('colors', []);
        if ($request->has('is_free_shipping')) {
            $validated['is_free_shipping'] = in_array($request->input('is_free_shipping'), ['1', 1, 'true', true], true);
        }

        $product = Product::create($validated);

        ActivityLog::log($request->user()->id, 'create_product', "Created product: {$product->name} (ID: {$product->id})", $request->ip());

        return response()->json($product, 201);
    }

    public function updateProduct(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        if ($request->has('sizes') && is_string($request->input('sizes'))) {
            $request->merge(['sizes' => json_decode($request->input('sizes'), true) ?? []]);
        }
        if ($request->has('colors') && is_string($request->input('colors'))) {
            $request->merge(['colors' => json_decode($request->input('colors'), true) ?? []]);
        }
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|required|numeric',
            'original_price' => 'nullable|numeric',
            'stock' => 'sometimes|required|integer',
            'status' => 'sometimes|required|in:active,draft,archived',
            'category_id' => 'nullable|exists:categories,id',
            'existing_images' => 'nullable|array',
            'sizes' => 'nullable|array',
            'sizes.*' => 'string|max:20',
            'colors' => 'nullable|array',
            'colors.*' => 'string|max:50',
            'is_free_shipping' => 'nullable|string',
        ]);

        // Validate uploaded image files separately
        if ($request->hasFile('images')) {
            $request->validate(['images.*' => 'image|max:10240']);
        }

        $imagePaths = $request->input('existing_images', []);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $imagePaths[] = $this->uploadImage($image, 'products');
            }
        }

        if (isset($validated['name']) && $validated['name'] !== $product->name) {
            $validated['slug'] = Str::slug($validated['name']) . '-' . uniqid();
        }

        $validated['images'] = $imagePaths;
        if ($request->has('sizes')) {
            $validated['sizes'] = is_array($request->input('sizes')) ? $request->input('sizes') : [];
        } else {
            $validated['sizes'] = [];
        }
        if ($request->has('colors')) {
            $validated['colors'] = is_array($request->input('colors')) ? $request->input('colors') : [];
        } else {
            $validated['colors'] = [];
        }
        if ($request->has('is_free_shipping')) {
            $validated['is_free_shipping'] = in_array($request->input('is_free_shipping'), ['1', 1, 'true', true], true);
        }
        $product->update($validated);

        ActivityLog::log($request->user()->id, 'update_product', "Updated product: {$product->name} (ID: {$product->id})", $request->ip());

        return response()->json($product);
    }

    public function destroyProduct($id)
    {
        $product = Product::findOrFail($id);
        $name = $product->name;
        $product->delete();

        ActivityLog::log(request()->user()->id, 'delete_product', "Deleted product: {$name} (ID: {$id})", request()->ip());

        return response()->json(['message' => 'Product deleted']);
    }

    // --- Category Management ---
    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image_url' => 'nullable|string|max:1000',
            'sort_order' => 'nullable|integer'
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        
        $category = Category::create($validated);

        ActivityLog::log($request->user()->id, 'create_category', "Created category: {$category->name} (ID: {$category->id})", $request->ip());

        return response()->json($category, 201);
    }


    public function updateCategory(Request $request, $id)
    {
        $category = Category::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'image_url' => 'nullable|string|max:1000',
            'sort_order' => 'integer'
        ]);

        if (isset($validated['name']) && $validated['name'] !== $category->name) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category->update($validated);

        ActivityLog::log($request->user()->id, 'update_category', "Updated category: {$category->name} (ID: {$category->id})", $request->ip());

        return response()->json($category);
    }

    public function destroyCategory($id)
    {
        $category = Category::findOrFail($id);
        $name = $category->name;
        $category->delete();

        ActivityLog::log(request()->user()->id, 'delete_category', "Deleted category: {$name} (ID: {$id})", request()->ip());

        return response()->json(['message' => 'Category deleted']);
    }


    // --- Order Management ---
    public function indexOrders(Request $request)
    {
        $perPage = (int) $request->query('per_page', 100);
        $perPage = min($perPage, 500); // cap at 500 to prevent memory issues

        $orders = Order::with('user', 'items.product')
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
            ],
        ]);
    }


    public function updateOrderStatus(Request $request, $id)
    {
        $order = Order::with('user')->findOrFail($id);
        
        $validated = $request->validate([
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled,paid,failed',
            'courier_name' => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:255',
            'tracking_url' => 'nullable|string|max:1000',
        ]);

        $oldStatus = $order->status;
        
        // Auto-mark as paid if admin manually moves from pending to processing
        if ($oldStatus === 'pending' && $validated['status'] === 'processing') {
            $order->payment_status = 'paid';
        }

        $order->update($validated);
        
        if ($oldStatus !== $validated['status']) {
            $history = $order->status_history ?? [];
            $historyItem = [
                'status' => $validated['status'],
                'timestamp' => now()->toIso8601String(),
            ];
            if ($validated['status'] === 'cancelled') {
                $historyItem['note'] = 'Cancelled by Admin';
            }
            $history[] = $historyItem;
            $order->status_history = $history;
            $order->save();
        }
        
        $order->refresh();

        if ($oldStatus !== $order->status) {
            try { 
                if ($order->user) {
                    (new OrderStatusUpdate($order))->send($order->user);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Order status email failed: ' . $e->getMessage());
            }

            // Insert system message into chat
            try {
                if ($order->user) {
                    \App\Models\SupportMessage::create([
                        'user_id' => $order->user->id,
                        'session_id' => null,
                        'is_admin_reply' => true,
                        'message' => 'SYSTEM NOTIFICATION: Your order ' . $order->reference . ' status has been updated to: ' . strtoupper($order->status)
                    ]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Order status chat update failed: ' . $e->getMessage());
            }
        }

        ActivityLog::log($request->user()->id, 'update_order', "Updated order status to {$order->status} (ID: {$order->id})", $request->ip());

        return response()->json($order);
    }

    // --- Users ---
    public function indexUsers()
    {
        $users = \App\Models\User::where('role', '!=', 'customer')
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($users);
    }

    public function destroyUser($id)
    {
        $user = \App\Models\User::findOrFail($id);
        
        // Root users can never be deleted
        if ($user->role === 'root') {
            return response()->json(['message' => 'Root admin accounts cannot be deleted.'], 403);
        }

        // Prevent deleting oneself
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'You cannot delete yourself.'], 403);
        }

        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }

    // --- Banners ---
    public function indexBanners()
    {
        return response()->json(\App\Models\Banner::latest()->get());
    }

    public function storeBanner(Request $request)
    {
        $request->validate([
            'title'     => 'required|string|max:255',
            'subtitle'  => 'nullable|string',
            'image_url' => 'nullable|string|max:1000',
            'start'     => 'nullable|date',
            'end'       => 'nullable|date',
            'status'    => 'nullable|in:active,draft',
            'cta'       => 'nullable|string',
        ]);

        $banner = \App\Models\Banner::create([
            'title'      => $request->input('title'),
            'subtitle'   => $request->input('subtitle') ?: null,
            'image_url'  => $request->input('image_url', ''),
            'status'     => $request->input('status', 'active'),
            'start_date' => $request->input('start') ?: null,
            'end_date'   => $request->input('end') ?: null,
            'cta'        => $request->input('cta') ?: null,
        ]);

        return response()->json($banner, 201);
    }

    public function updateBanner(Request $request, $id)
    {
        $banner = \App\Models\Banner::findOrFail($id);

        $request->validate([
            'title'     => 'nullable|string|max:255',
            'subtitle'  => 'nullable|string',
            'image_url' => 'nullable|string|max:1000',
            'start'     => 'nullable|date',
            'end'       => 'nullable|date',
            'status'    => 'nullable|in:active,draft',
            'cta'       => 'nullable|string',
        ]);

        $data = [];
        if ($request->has('title'))     $data['title']      = $request->input('title');
        if ($request->has('subtitle'))  $data['subtitle']   = $request->input('subtitle') ?: null;
        if ($request->has('image_url')) $data['image_url']  = $request->input('image_url');
        if ($request->has('status'))    $data['status']     = $request->input('status');
        if ($request->has('start'))     $data['start_date'] = $request->input('start') ?: null;
        if ($request->has('end'))       $data['end_date']   = $request->input('end') ?: null;
        if ($request->has('cta'))       $data['cta']        = $request->input('cta') ?: null;

        $banner->update($data);
        return response()->json($banner->fresh());
    }

    public function destroyBanner($id)
    {
        \App\Models\Banner::destroy($id);
        return response()->json(['message' => 'deleted']);
    }

    // --- Hero Slides ---
    public function indexHeroSlides()
    {
        return response()->json(\App\Models\HeroSlide::orderBy('sort_order')->get());
    }

    /**
     * Generate a Cloudinary signed upload signature so the browser
     * can upload images directly to Cloudinary (no Render timeout risk).
     */
    public function cloudinarySignature(Request $request)
    {
        $cloudUrl = env('CLOUDINARY_URL', '');
        // Parse cloudinary://API_KEY:API_SECRET@CLOUD_NAME
        preg_match('#cloudinary://([^:]+):([^@]+)@(.+)#', $cloudUrl, $matches);
        $apiKey    = $matches[1] ?? '';
        $apiSecret = $matches[2] ?? '';
        $cloudName = $matches[3] ?? '';

        $timestamp = time();
        $folder    = 'hero-slides';

        // Build the string to sign (params sorted alphabetically)
        $paramsToSign = "folder={$folder}&timestamp={$timestamp}";
        $signature    = sha1($paramsToSign . $apiSecret);

        return response()->json([
            'api_key'    => $apiKey,
            'cloud_name' => $cloudName,
            'timestamp'  => $timestamp,
            'signature'  => $signature,
            'folder'     => $folder,
        ]);
    }

    /**
     * Generate a Cloudinary signed upload signature for customer inquiry/chat image uploads.
     * Any authenticated user (including customers) can use this.
     */
    public function customerCloudinarySignature(Request $request)
    {
        $cloudUrl = env('CLOUDINARY_URL', '');
        preg_match('#cloudinary://([^:]+):([^@]+)@(.+)#', $cloudUrl, $matches);
        $apiKey    = $matches[1] ?? '';
        $apiSecret = $matches[2] ?? '';
        $cloudName = $matches[3] ?? '';

        $timestamp = time();
        $folder    = 'inquiry-uploads';

        $paramsToSign = "folder={$folder}&timestamp={$timestamp}";
        $signature    = sha1($paramsToSign . $apiSecret);

        return response()->json([
            'api_key'    => $apiKey,
            'cloud_name' => $cloudName,
            'timestamp'  => $timestamp,
            'signature'  => $signature,
            'folder'     => $folder,
        ]);
    }

    public function storeHeroSlide(Request $request)
    {
        $request->validate([
            'title'     => 'required|string|max:255',
            'subtitle'  => 'nullable|string|max:500',
            'image_url' => 'nullable|string|max:1000',
            'badge'     => 'nullable|string|max:100',
            'cta'       => 'nullable|string|max:100',
            'dark'      => 'nullable',
        ]);

        $slide = \App\Models\HeroSlide::create([
            'title'      => $request->input('title'),
            'subtitle'   => $request->input('subtitle') ?: null,
            'image_url'  => $request->input('image_url', ''),
            'badge'      => $request->input('badge') ?: null,
            'cta_text'   => $request->input('cta') ?: null,
            'is_dark'    => in_array($request->input('dark'), ['1', 1, 'true', true], true),
            'sort_order' => (int)(\App\Models\HeroSlide::max('sort_order') ?? 0) + 1,
        ]);

        return response()->json($slide, 201);
    }

    public function updateHeroSlide(Request $request, $id)
    {
        $slide = \App\Models\HeroSlide::findOrFail($id);

        $request->validate([
            'title'     => 'nullable|string|max:255',
            'subtitle'  => 'nullable|string|max:500',
            'image_url' => 'nullable|string|max:1000',
            'badge'     => 'nullable|string|max:100',
            'cta'       => 'nullable|string|max:100',
            'dark'      => 'nullable',
        ]);

        $data = [];
        if ($request->has('title'))     $data['title']     = $request->input('title');
        if ($request->has('subtitle'))  $data['subtitle']  = $request->input('subtitle') ?: null;
        if ($request->has('image_url')) $data['image_url'] = $request->input('image_url');
        if ($request->has('badge'))     $data['badge']     = $request->input('badge') ?: null;
        if ($request->has('cta'))       $data['cta_text']  = $request->input('cta') ?: null;
        if ($request->has('dark'))      $data['is_dark']   = in_array($request->input('dark'), ['1', 1, 'true', true], true);

        $slide->update($data);
        return response()->json($slide->fresh());
    }

    public function destroyHeroSlide($id)
    {
        \App\Models\HeroSlide::destroy($id);
        return response()->json(['message' => 'deleted']);
    }

    // --- Shipping Settings ---
    public function updateShippingSettings(Request $request)
    {
        $validated = $request->validate([
            'shipping_enabled' => 'required|boolean',
            'shipping_fee_lagos_kwara' => 'required|numeric',
            'shipping_fee_other' => 'required|numeric',
        ]);

        foreach (['shipping_enabled', 'shipping_fee_lagos_kwara', 'shipping_fee_other'] as $key) {
            $setting = \App\Models\Setting::firstOrNew(['key' => $key]);
            // Convert boolean to string "true"/"false" if needed, or store as is
            $setting->value = is_bool($validated[$key]) ? ($validated[$key] ? 'true' : 'false') : (string)$validated[$key];
            $setting->save();
        }

        return response()->json(['message' => 'Shipping settings updated']);
    }

    // --- Theme ---
    public function updateTheme(Request $request)
    {
        $validated = $request->validate([
            'theme_colors' => 'required|string',
        ]);
        $setting = \App\Models\Setting::firstOrNew(['key' => 'theme_colors']);
        $setting->value = $validated['theme_colors'];
        $setting->save();
        return response()->json(['message' => 'Theme updated']);
    }

    // --- Marketing ---
    public function broadcastOffer(Request $request)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        $count = User::count();

        // Defer the heavy sending work until after the HTTP response is delivered,
        // preventing Render's 30-second timeout from killing the request mid-send.
        dispatch_after_response(function () use ($validated) {
            User::chunk(100, function ($users) use ($validated) {
                $notification = new MarketingOffer($validated);
                foreach ($users as $user) {
                    try { $notification->send($user); } catch (\Throwable $e) {}
                }
            });
        });

        return response()->json(['message' => 'Marketing offer is being sent to ' . $count . ' users.']);
    }


    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'role'  => 'required|in:superadmin,manager,staff,inventory',
        ]);

        $tempPassword = \Illuminate\Support\Str::random(12);

        $user = User::create([
            'name'                 => htmlspecialchars(strip_tags(trim($validated['name'])), ENT_QUOTES, 'UTF-8'),
            'email'                => strtolower(trim($validated['email'])),
            'password'             => $tempPassword,
            'role'                 => $validated['role'],
            'must_change_password' => true,
        ]);

        try {
            (new \App\Notifications\AdminInvitationNotification($tempPassword))->send($user);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Admin invitation email failed', ['error' => $e->getMessage()]);
            // Don't return a 500 here — user was created successfully. Warn the admin instead.
            return response()->json([
                'user'    => $user,
                'message' => 'User created, but invitation email failed to send: ' . $e->getMessage() . '. Please share the login credentials manually.',
                'warning' => true,
            ], 201);
        }


        return response()->json([
            'user'    => $user,
            'message' => 'User invited successfully. Login credentials have been sent to ' . $user->email,
        ], 201);
    }

    public function purgeOrders(Request $request)
    {
        $count = Order::count();
        \App\Models\OrderItem::query()->delete();
        Order::query()->truncate();

        ActivityLog::log($request->user()->id, 'purge_orders', "Purged all orders", $request->ip());

        return response()->json(['message' => "All {$count} order(s) have been purged successfully."]);
    }

    public function indexCustomers(Request $request)
    {
        $customers = User::where('role', 'customer')
            ->orderBy('created_at', 'desc')
            ->get(['id', 'name', 'email', 'phone', 'created_at', 'updated_at']);

        return response()->json($customers);
    }

    public function getNotifications(Request $request)
    {
        $notifications = \App\Models\AdminNotification::orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
            
        return response()->json($notifications);
    }

    public function markNotificationsRead(Request $request)
    {
        \App\Models\AdminNotification::where('is_read', false)->update(['is_read' => true]);
        return response()->json(['message' => 'Notifications marked as read.']);
    }
}
