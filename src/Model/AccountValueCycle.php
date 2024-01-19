<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Model;

use Illuminate\Database\Eloquent\Model;

class AccountValueCycle extends Model
{
    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = 'account_value_cycle';

    /**
     * The attributes that are mass assignable
     *
     * @var array
     */
    protected $fillable = [
        'bot_id',
        'period',
        'current_value',
        'target_value',
        'account_balance',
        'done',
    ];
}
