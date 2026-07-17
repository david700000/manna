<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Wishlist;

class WishlistController extends Controller
{
    /**
     * Get all wishlisted product IDs for the authenticated user.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $items = Wishlist::with('product')
            ->where('user_id', $user->id)
            ->get()
            ->map(function ($item) {
                if (!$item->product) return null;
                $imgs = [];
                if (is_array($item->product->images)) {
                    $imgs = $item->product->images;
                } elseif (is_string($item->product->images)) {
                    $imgs = json_decode($item->product->images, true) ?: [];
                }
                return [
                    'product_id' => $item->product_id,
                    'product' => [
                        'id'     => $item->product->id,
                        'name'   => $item->product->name,
                        'price'  => $item->product->price,
                        'image'  => count($imgs) > 0 ? $imgs[0] : null,
                        'images' => $imgs,
                        'stock'  => $item->product->stock,
                        'category' => $item->product->category?->name ?? '',
                    ],
                ];
            })
            ->filter()
            ->values();

        return response()->json($items);
    }

    /**
     * Toggle a product in/out of the user's wishlist.
     */
    public function toggle(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $user = $request->user();

        $existing = Wishlist::where('user_id', $user->id)
            ->where('product_id', $validated['product_id'])
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json(['wishlisted' => false, 'message' => 'Removed from wishlist.']);
        }

        Wishlist::create([
            'user_id'    => $user->id,
            'product_id' => $validated['product_id'],
        ]);

        return response()->json(['wishlisted' => true, 'message' => 'Added to wishlist.']);
    }
}
