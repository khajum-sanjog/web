<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPaymentGateway extends Model
{
    use HasFactory;

    // Table associated with the model
    protected $table = 'user_payment_gateways';

    // The attributes that are mass assignable
    protected $fillable = [
        'user_id',
        'payment_gateway_id',
        'payment_gateway_name',
        'created_by',
        'status',
        'is_live_mode',
        'updated_by'
    ];

    // Define relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function paymentGateway()
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    public function userPaymentCredentials()
    {
        return $this->hasMany(UserPaymentCredential::class, 'user_payment_gateway_id');
    }

    public function userPaymentSettings()
    {
        return $this->hasMany(UserPaymentSetting::class, 'user_payment_gateway_id');
    }
}
