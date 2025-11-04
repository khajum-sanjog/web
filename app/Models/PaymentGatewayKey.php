<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGatewayKey extends Model
{
    protected $table = 'payment_gateway_keys';

    protected $guarded = [
    ];

    public function paymentGateway()
    {
        return $this->belongsTo(PaymentGateway::class);
    }

}
