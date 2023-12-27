<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Model;

use Illuminate\Database\Eloquent\Model;

class BotLogs extends Model
{
    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = 'bot_logs';

    /**
     * The attributes that are mass assignable
     *
     * @var array
     */
    protected $fillable = [
        'bot_id',
        'level',
        'message',
    ];
}
