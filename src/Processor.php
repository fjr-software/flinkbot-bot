<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

class Processor
{
    /**
     * @const int
     */
    public const MAX_RETRY = 3;

    /**
     * @const string
     */
    public const STATUS_RUN = 'run';

    /**
     * @const string
     */
    public const STATUS_STOP = 'stop';

    /**
     * @var LoopInterface|null
     */
    private ?LoopInterface $loop = null;

    /**
     * @var float|null
     */
    private ?float $startTime = null;

    /**
     * @var float
     */
    private ?float $endTime = null;

    /**
     * @var array
     */
    private array $retrys = [];

    /**
     * @var array
     */
    private array $process = [];

    /**
     * @var array
     */
    private array $status = [];

    /**
     * Constructor
     *
     * @param int $customerId
     * @param array $bots
     */
    public function __construct(
        private readonly int $customerId,
        private readonly array $bots,
        private readonly string $processFile,
        private readonly int $timeout = 60
    ) {
        $this->loop = Loop::get();
    }

    public function process(): void
    {
        $this->startTime = microtime(true);

        foreach ($this->bots as $botId => $symbols) {
            $this->processBot($botId, $symbols);
        }

        $this->loop->run();

        $this->endTime = microtime(true);
    }

    public function timeExecution(): ?float
    {
        if (!$this->startTime || !$this->endTime) {
            return null;
        }

        return $this->endTime - $this->startTime;
    }

    private function processBot(int $botId, array $symbols): void
    {
        foreach ($symbols as $symbol) {
            $this->status[$botId][$symbol] = self::STATUS_RUN;

            $this->loop->futureTick(function () use ($botId, $symbol) {
                $this->process[$botId][$symbol] = $this->retrySymbol($botId, $symbol);
            });
        }
    }

    public function closeProcess(int $botId, string $symbol, bool $force = false): void
    {
        $this->status[$botId][$symbol] = self::STATUS_STOP;

        if ($process = ($this->process[$botId][$symbol] ?? false)) {
            if ($process->isRunning()) {
                if ($force) {
                    foreach ($process->pipes as $pipe) {
                        $pipe->close();
                    }

                    $process->terminate(Analyzer::RESULT_CLOSED);
                } else {
                    $process->stdin->write('@STOP');
                    $process->stdin->end();
                }
            }
        }
    }

    public function closeAllProcess(bool $force = false): void
    {
        foreach ($this->status as $botId => $symbols) {
            foreach ($symbols as $symbol => $status) {
                $this->status[$botId][$symbol] = self::STATUS_STOP;
            }
        }

        if ($processList = ($this->process ?? [])) {
            foreach ($processList as $symbols) {
                foreach ($symbols as $process) {
                    if ($process->isRunning()) {
                        if ($force) {
                            foreach ($process->pipes as $pipe) {
                                $pipe->close();
                            }

                            $process->terminate(Analyzer::RESULT_CLOSED);
                        } else {
                            $process->stdin->write('@STOP');
                            $process->stdin->end();
                        }
                    }
                }
            }
        }
    }

    private function retrySymbol(int $botId, string $symbol): Process
    {
        $process = new Process($this->buildCommand($botId, $symbol));

        $timer = $this->loop->addTimer($this->timeout, function () use ($process, $botId, $symbol) {
            if (!$process->isRunning()) {
                echo "STOP FINISHED-{$this->customerId}-{$botId}-{$symbol}\n";
                return;
            }

            foreach ($process->pipes as $pipe) {
                $pipe->close();
            }

            $exitCode = Analyzer::RESULT_TIMEOUT;

            if ($this->status[$botId][$symbol] === self::STATUS_STOP) {
                $exitCode = Analyzer::RESULT_CLOSED_TIMEOUT;
            }

            $process->terminate($exitCode);

            echo "TIMEOUT-{$this->customerId}-{$botId}-{$symbol}\n";
        });

        $this->retrys[$botId][$symbol] = $this->retrys[$botId][$symbol] ?? 0;

        $this->loop->futureTick(function () use ($process, $botId, $symbol, &$timer) {
            $process->start($this->loop);

            $process->on('exit', function (?int $exitCode = null, ?int $termSignal = null) use ($process, $botId, $symbol, &$timer) {
                $exitCode = $exitCode ?? $termSignal;

                switch (true) {
                    case $exitCode === Analyzer::RESULT_DEFAULT:
                    case $exitCode === Analyzer::RESULT_SUCCESS:
                        echo "Bot-{$this->customerId}-{$botId}-{$symbol} - finished\n";
                        break;
                    case $exitCode === Analyzer::RESULT_CLOSED:
                        echo "Bot-{$this->customerId}-{$botId}-{$symbol} - closed\n";
                        break;
                    case $exitCode === Analyzer::RESULT_CLOSED_TIMEOUT:
                        echo "Bot-{$this->customerId}-{$botId}-{$symbol} - finished timeout\n";
                        break;
                    default:
                        if ($exitCode !== Analyzer::RESULT_RESTART) {
                            $this->retrys[$botId][$symbol]++;
                        }

                        if ($this->retrys[$botId][$symbol] >= self::MAX_RETRY) {
                            echo "Tentativas maximas Bot-{$this->customerId}-{$botId}-{$symbol}\n";
                        } else {
                            $mgs = 'Iniciando Bot';

                            if ($exitCode !== Analyzer::RESULT_RESTART) {
                                $mgs = 'Erro ao processar Bot';
                            }

                            echo "{$mgs}-{$this->customerId}-{$botId}-{$symbol} - {$exitCode}:{$termSignal}\n";

                            $this->loop->futureTick(function () use ($botId, $symbol) {
                                $this->process[$botId][$symbol] = $this->retrySymbol($botId, $symbol);
                            });
                        }
                }

                $this->loop->cancelTimer($timer);

                $process->close();
            });

            $process->stdout->on('data', function ($output) use ($botId, $symbol) {
                $outputTmp = explode("\n", $output);
                $outputTmp = implode("\n\t", $outputTmp);

                echo "Bot-{$this->customerId}-{$botId}-{$symbol} - output:\n\t{$outputTmp}\n";
            });
        });

        return $process;
    }

    private function buildCommand(int $botId, string $symbol): array
    {
        return [
            '/usr/bin/php',
            $this->processFile,
            '--type=symbol',
            "--bot={$botId}",
            "--symbol={$symbol}"
        ];
    }
}
