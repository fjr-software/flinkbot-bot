<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

use FjrSoftware\Flinkbot\Bot\Model\Bots;

class Bot
{
    /**
     * @var Processor
     */
    private Processor $processor;

    /**
     * @var bool
     */
    private bool $isRunning = false;

    /**
     * Constructor
     *
     * @param int $customerId
     * @param string $processFile
     * @param int $timeout
     */
    public function __construct(
        private readonly int $customerId,
        private readonly string $processFile,
        private readonly int $timeout
    ) {
        $this->load();
    }

    /**
     * Is running
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    /**
     * Run
     *
     * @return void
     */
    public function run(): void
    {
        if (!$this->isRunning) {
            $this->isRunning = true;
            $this->processor->process();
        }
    }

    /**
     * Close
     *
     * @param int $botId
     * @param string $symbol
     * @param bool $force
     * @return void
     */
    public function close(int $botId, string $symbol, bool $force = false): void
    {
        $this->processor->closeProcess($botId, $symbol, $force);
    }

    /**
     * Close all
     *
     * @param bool $force
     * @return void
     */
    public function closeAll(bool $force = false): void
    {
        $this->processor->closeAllProcess($force);
    }

    /**
     * Load
     *
     * @return void
     */
    private function load(): void
    {
        $this->processor = new Processor(
            $this->customerId,
            $this->getData(),
            $this->processFile,
            $this->timeout
        );
    }

    /**
     * Get data
     *
     * @return array
     */
    private function getData(): array
    {
        $bots = Bots::where(['user_id' => $this->customerId])->get();
        $symbols = [];

        foreach ($bots as $bot) {
            foreach ($bot->symbol as $symbol) {
                if ($symbol->status === 'active') {
                    $symbols[$symbol->bot_id][] = $symbol->pair;
                }
            }
        }

        return $symbols;
    }
}
