<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserPaymentCredential extends Model
{
    use HasFactory;
    public $timestamps = true;
    protected $table = 'user_payment_credentials';
    protected $fillable = [
        'user_payment_gateway_id',
        'key',
        'value',
    ];

    public function userPaymentGateway()
    {
        return $this->belongsTo(UserPaymentGateway::class, 'user_payment_gateway_id');
    }


}
