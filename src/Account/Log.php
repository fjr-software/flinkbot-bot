<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Account;

use FjrSoftware\Flinkbot\Bot\Model\BotLogs;

class Log
{
    /**
     * Constructor
     *
     * @param Bot $bot
     */
    public function __construct(
        private readonly Bot $bot
    ) {
    }

    /**
     * Register log
     *
     * @param LogLevel $level
     * @param string $message
     * @return void
     */
    public function register(LogLevel $level, string $message): void
    {
        BotLogs::create([
            'bot_id' => $this->bot->getId(),
            'level' => $level,
            'message' => $message
        ]);
    }
}
