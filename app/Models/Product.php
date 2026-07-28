<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'price', 'original_price', 'stock', 
        'category_id', 'status', 'images', 'sizes', 'colors', 'is_free_shipping'
    ];

    protected $casts = [
        'images' => 'array',
        'sizes'  => 'array',
        'colors' => 'array',
        'price'  => 'decimal:2',
        'original_price' => 'decimal:2',
        'is_free_shipping' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function ratings()
    {
        return $this->hasMany(ProductRating::class);
    }
}
