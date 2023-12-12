<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

class Bot
{
    private Processor $processor;
    private bool $isRunning = false;

    public function __construct(
        private readonly int $customerId,
        private readonly string $processFile,
        private readonly int $timeout
    ) {
        $this->load();
    }

    private function load(): void
    {
        $this->processor = new Processor(
            $this->customerId,
            $this->getData()['customer'][$this->customerId]['bots'],
            $this->processFile,
            $this->timeout
        );
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    public function run(): void
    {
        if (!$this->isRunning) {
            $this->isRunning = true;
            $this->processor->process();
        }
    }

    public function close(int $botId, string $symbol, bool $force = false): void
    {
        $this->processor->closeProcess($botId, $symbol, $force);
    }

    public function closeAll(bool $force = false): void
    {
        $this->processor->closeAllProcess($force);
    }

    private function getData(): array
    {
        return [
            'customer' => [
                1 => [
                    'bots' => [
                        1 => ['BTCUSDT', 'BNBUSDT', 'ETHUSDT'],
                        2 => ['SOLUSDT', 'BTCUSDT', 'ETHUSDT'],
                    ],
                ],
                2 => [
                    'bots' => [
                        1 => ['BTCUSDT'],
                    ],
                ],
                3 => [
                    'bots' => [
                        1 => ['BNBUSDT'],
                    ],
                ],
                4 => [
                    'bots' => [
                        1 => ['ETHUSDT'],
                    ],
                ],
                5 => [
                    'bots' => [
                        1 => ['SOLUSDT'],
                    ],
                ],
            ],
        ];
    }
}
