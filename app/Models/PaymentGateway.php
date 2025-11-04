<?php

namespace App\Models;

use App\Models\PaymentGatewayKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentGateway extends Model
{
    use HasFactory;
    protected $table = 'payment_gateways';
    protected $guarded = [];

    // Cast the 'credentials' attribute to an array (for automatic JSON handling)
    protected $casts = [
        'required_keys' => 'array', // This tells Laravel to automatically cast the 'required_keys' field as an array
    ];

    /**
     * This method defines a relationship to retrieve all keys (such as API keys or credentials)
     * that belong to the current payment gateway instance.
     */
    public function paymentGatewayKeys()
    {
        return $this->hasMany(PaymentGatewayKey::class, 'payment_gateway_id');
    }

    /**
     * Get the child payment gateways associated with this gateway.
     *
     * This accessor retrieves the related child gateways, if any, for the current payment gateway instance.
     *
     * @return mixed The collection or array of child payment gateways.
     */
    public function wallets()
    {
        return $this->belongsToMany(PaymentGateway::class, 'payment_gateway_keys', 'parent', 'payment_gateway_id')
                   ->distinct();
    }
}
