<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Account;

enum LogLevel: string
{
    case LEVEL_INFO = 'INFO';
    case LEVEL_WARNING = 'WARNING';
    case LEVEL_ERROR = 'ERROR';
    case LEVEL_DEBUG = 'DEBUG';
}
