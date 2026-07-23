<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'monnify_reference',
        'transaction_reference',
        'amount',
        'status',
        'payment_method',
        'raw_response',
        'refund_reference',
        'refund_status',
        'refund_amount',
        'refunded_at',
    ];

    protected $casts = [
        'refunded_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
