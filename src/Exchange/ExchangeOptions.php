<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Exchange;

use FjrSoftware\Flinkbot\Exchange\Binance;

enum ExchangeOptions: string
{
    case NONE = 'NONE';
    case BINANCE = 'BINANCE';

    public function getClass(): string
    {
        return match($this) {
            static::NONE => '',
            static::BINANCE => Binance::class,
        };
    }
}
