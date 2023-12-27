<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Model;

use Illuminate\Database\Eloquent\Model;

class Symbols extends Model
{
    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = 'symbols';

    /**
     * The attributes that are mass assignable
     *
     * @var array
     */
    protected $fillable = [
        'bot_id',
        'pair',
        'leverage',
        'status',
        'base_quantity',
        'min_quantity',
    ];
}
