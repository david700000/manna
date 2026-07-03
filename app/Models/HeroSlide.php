<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeroSlide extends Model
{
    protected $fillable = ['title', 'subtitle', 'image_url', 'badge', 'cta_text', 'is_dark', 'sort_order'];
}
