<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Category;
use App\Models\Banner;
use App\Models\HeroSlide;
use App\Models\Setting;

class PublicController extends Controller
{
    public function products()
    {
        return response()->json(Product::with('category')->where('status', 'active')->get());
    }

    public function product($slug)
    {
        $product = Product::with('category')->where('slug', $slug)->where('status', 'active')->firstOrFail();
        return response()->json($product);
    }

    public function categories()
    {
        return response()->json(Category::orderBy('sort_order')->get());
    }

    public function banners()
    {
        return response()->json(Banner::where('status', 'active')->get());
    }

    public function heroSlides()
    {
        return response()->json(HeroSlide::orderBy('sort_order')->get());
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

        $adminUser = (object)[
            'email' => 'mannabridalsupport@gmail.com',
            'name' => 'Manna Bridal Support'
        ];

        try {
            (new \App\Notifications\ContactMessageNotification($messageData))->send($adminUser);
            return response()->json(['message' => 'Message sent successfully.']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to send message.', 'error' => $e->getMessage()], 500);
        }
    }
}
