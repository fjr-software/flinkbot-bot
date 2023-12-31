<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Exchange;

use FjrSoftware\Flinkbot\Exchange\Binance;

enum ExchangeOptions: string
{
    case NONE = '';
    case BINANCE = Binance::class;
}
