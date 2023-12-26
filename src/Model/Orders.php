<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Model;

use Illuminate\Database\Eloquent\Model;

class Orders extends Model
{
    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = 'orders';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'symbol_id',
        'side',
        'position_side',
        'type',
        'quantity',
        'price',
        'stop_price',
        'close_position',
        'time_in_force',
        'order_id',
        'client_order_id',
        'status'
    ];
}
