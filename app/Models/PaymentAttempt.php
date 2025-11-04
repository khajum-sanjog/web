<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'store_id',
        'temp_order_number',
        'member_email',
        'member_name',
        'gateway',
        'amount',
        'card_last_4_digit',
        'card_expire_date',
        'transaction_id',
        'charge_id',
        'status',
        'comment',
        'payment_handle_comment',
        'refund_void_transaction_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
