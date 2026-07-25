<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'price', 'original_price', 'stock', 
        'category_id', 'status', 'images', 'sizes'
    ];

    protected $casts = [
        'images' => 'array',
        'sizes'  => 'array',
        'price'  => 'decimal:2',
        'original_price' => 'decimal:2',
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
