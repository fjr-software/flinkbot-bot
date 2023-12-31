<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Model;

use Illuminate\Database\Eloquent\Model;

class ApiRateLimit extends Model
{
    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = 'api_rate_limit';

    /**
     * The attributes that are mass assignable
     *
     * @var array
     */
    protected $fillable = [
        'ip',
        'country',
        'exchange',
        'request_count',
        'request_last_time',
        'request_rate_limit',
        'request_reset_interval',
        'request_status',
        'order_count',
        'order_last_time',
        'order_rate_limit',
        'order_reset_interval',
        'order_status',
        'status',
    ];
}
