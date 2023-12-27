<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * Get orders relation
     *
     * @return HasMany
     */
    public function order(): HasMany
    {
        return $this->hasMany(Orders::class, 'id', 'symbol_id');
    }
}
