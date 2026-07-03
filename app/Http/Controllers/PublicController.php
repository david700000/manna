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
}
