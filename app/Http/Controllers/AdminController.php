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

    public function storeProduct(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric',
            'stock' => 'required|integer',
            'status' => 'required|in:active,draft,archived',
            'category_id' => 'nullable|exists:categories,id',
        ]);

        // Validate uploaded image files separately (field name is 'images' from multipart images[])
        if ($request->hasFile('images')) {
            $request->validate(['images.*' => 'image|max:10240']);
        }

        $imagePaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $imagePaths[] = $this->uploadImage($image, 'products');
            }
        }

        $validated['slug'] = Str::slug($validated['name']) . '-' . uniqid();
        $validated['images'] = json_encode($imagePaths);

        $product = Product::create($validated);

        return response()->json($product, 201);
    }

    public function updateProduct(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|required|numeric',
            'stock' => 'sometimes|required|integer',
            'status' => 'sometimes|required|in:active,draft,archived',
            'category_id' => 'nullable|exists:categories,id',
            'existing_images' => 'nullable|array', // URLs to keep
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

        $validated['images'] = json_encode($imagePaths);
        $product->update($validated);

        return response()->json($product);
    }

    public function destroyProduct($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();
        return response()->json(['message' => 'Product deleted']);
    }

    // --- Category Management ---
    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'integer|default:0'
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        
        $category = Category::create($validated);
        return response()->json($category, 201);
    }

    public function updateCategory(Request $request, $id)
    {
        $category = Category::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'integer'
        ]);

        if (isset($validated['name']) && $validated['name'] !== $category->name) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category->update($validated);
        return response()->json($category);
    }

    public function destroyCategory($id)
    {
        $category = Category::findOrFail($id);
        $category->delete();
        return response()->json(['message' => 'Category deleted']);
    }

    // --- Order Management ---
    public function indexOrders(Request $request)
    {
        $orders = Order::with('user', 'items.product')->latest()->get();
        return response()->json($orders);
    }

    public function updateOrderStatus(Request $request, $id)
    {
        $order = Order::findOrFail($id);
        
        $validated = $request->validate([
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled,paid',
        ]);

        $oldStatus = $order->status;
        $order->update($validated);

        if ($oldStatus !== $order->status) {
            try { (new OrderStatusUpdate($order))->send($order->user); } catch (\Throwable $e) {}
        }

        return response()->json($order);
    }

    // --- Users ---
    public function indexUsers()
    {
        $users = \App\Models\User::orderBy('created_at', 'desc')->get();
        return response()->json($users);
    }

    public function destroyUser($id)
    {
        $user = \App\Models\User::findOrFail($id);
        
        // Prevent deleting oneself
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'You cannot delete yourself.'], 403);
        }

        // Prevent deleting root users unless the requester is also root
        if ($user->role === 'root' && auth()->user()->role !== 'root') {
            return response()->json(['message' => 'You cannot delete a root admin.'], 403);
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
        ]);

        $banner = \App\Models\Banner::create([
            'title'      => $request->input('title'),
            'subtitle'   => $request->input('subtitle') ?: null,
            'image_url'  => $request->input('image_url', ''),
            'status'     => $request->input('status', 'active'),
            'start_date' => $request->input('start') ?: null,
            'end_date'   => $request->input('end') ?: null,
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
        ]);

        $data = [];
        if ($request->has('title'))     $data['title']      = $request->input('title');
        if ($request->has('subtitle'))  $data['subtitle']   = $request->input('subtitle') ?: null;
        if ($request->has('image_url')) $data['image_url']  = $request->input('image_url');
        if ($request->has('status'))    $data['status']     = $request->input('status');
        if ($request->has('start'))     $data['start_date'] = $request->input('start') ?: null;
        if ($request->has('end'))       $data['end_date']   = $request->input('end') ?: null;

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

        $users = User::all();
        $notification = new MarketingOffer($validated);
        foreach ($users as $user) {
            try { $notification->send($user); } catch (\Throwable $e) {}
        }

        return response()->json(['message' => 'Marketing offer sent to ' . $users->count() . ' users.']);
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
            return response()->json(['message' => 'User created but email failed: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'user'    => $user,
            'message' => 'User invited successfully. Login credentials have been sent to ' . $user->email,
        ], 201);
    }
}
