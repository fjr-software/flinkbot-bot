<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Positions extends Model
{
    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = 'positions';

    /**
     * The attributes that are mass assignable
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'symbol_id',
        'leverage',
        'side',
        'entry_price',
        'size',
        'pnl_roi_percent',
        'pnl_roi_value',
        'margin_account_percent',
        'margin_symbol_percent',
        'mark_price',
        'liquid_price',
        'margin_type',
        'status',
    ];

    /**
     * Get symbols relation
     *
     * @return HasOne
     */
    public function symbol(): HasOne
    {
        return $this->hasOne(Symbols::class, 'id', 'symbol_id');
    }
}
