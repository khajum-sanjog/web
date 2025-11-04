<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestLog extends Model
{
    protected $table = 'request_logs';
    protected $guarded = [];

    protected $casts = [
    'request' => 'json',
    'headers' => 'json',
    'queries' => 'json',
    'query_parameters' => 'json',
];
}
