<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Category;
use App\Models\Banner;
use App\Models\HeroSlide;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class PublicController extends Controller
{
    public function products(Request $request)
    {
        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            return response()->json(Product::with('category')
                ->where('status', 'active')
                ->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                })->get());
        }

        $products = Cache::remember('public_products', 3600, function () {
            return Product::where('status', 'active')
                ->with('category')
                ->latest()
                ->get()
                ->toArray();
        });

        return response()->json($products);
    }

    public function product($slug)
    {
        $product = Product::with(['category', 'ratings.user'])->where('slug', $slug)->where('status', 'active')->firstOrFail();
        return response()->json($product);
    }

    public function categories()
    {
        $categories = Category::orderBy('sort_order')->get();
        return response()->json($categories);
    }

    public function banners()
    {
        $banners = Banner::where('status', 'active')->get();
        return response()->json($banners);
    }

    public function heroSlides()
    {
        $slides = HeroSlide::orderBy('sort_order')->get();
        return response()->json($slides);
    }

    public function settings()
    {
        $settings = Setting::all()->pluck('value', 'key');
        return response()->json($settings);
    }

    public function sendSupportMessage(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string',
        ]);

        $user = $request->user();

        $messageData = [
            'name' => $user ? $user->name : 'Guest Customer',
            'email' => $user ? $user->email : 'guest@mannabridal.com',
            'message' => $validated['message'],
        ];

        try {
            $admins = \App\Models\User::whereNotIn('role', ['customer'])->get();
            foreach ($admins as $adminUser) {
                (new \App\Notifications\ContactMessageNotification($messageData))->send($adminUser);
            }
            return response()->json(['message' => 'Message sent successfully.']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to send message.', 'error' => $e->getMessage()], 500);
        }
    }
}
