<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'customer_name', 'customer_email', 'customer_phone',
        'subtotal', 'total', 'status', 'payment_status', 'payment_reference',
        'delivery_address', 'notes',
        'courier_name', 'tracking_number', 'tracking_url', 'status_history'
    ];

    protected $casts = [
        'delivery_address' => 'array',
        'status_history' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
