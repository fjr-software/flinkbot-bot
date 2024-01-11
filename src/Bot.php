<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

use FjrSoftware\Flinkbot\Bot\Model\Bots;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

class Bot
{
    /**
     * @var Processor
     */
    private Processor $processor;

    /**
     * @var LoopInterface|null
     */
    private ?LoopInterface $loop = null;

    /**
     * @var bool
     */
    private bool $isRunning = false;

    /**
     * @var string
     */
    private string $host = '127.0.0.1';

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
        $this->loop = Loop::get();
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
            $this->processor->loop->addPeriodicTimer(
                $this->timeout,
                fn() => $this->processor->restart()
            );

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
     * Set host
     *
     * @param string $host
     * @return void
     */
    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    /**
     * Get host
     *
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
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
        $bots = Bots::where([
            'user_id' => $this->customerId,
            'status' => 'active',
        ])->get();
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
