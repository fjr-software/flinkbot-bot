<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

use FjrSoftware\Flinkbot\Bot\Account\Log;
use FjrSoftware\Flinkbot\Bot\Account\LogLevel;
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
     * @param string $processFile
     * @param int $timeout
     */
    public function __construct(
        private readonly int $customerId,
        private readonly array $bots,
        private readonly string $processFile,
        private readonly int $timeout = 60
    ) {
        $this->loop = Loop::get();
    }

    /**
     * Process
     *
     * @return void
     */
    public function process(): void
    {
        $this->startTime = microtime(true);

        foreach ($this->bots as $botId => $symbols) {
            $this->processBot($botId, $symbols);
        }

        $this->loop->run();

        $this->endTime = microtime(true);
    }

    /**
     * Get time execution
     *
     * @return float|null
     */
    public function timeExecution(): ?float
    {
        if (!$this->startTime || !$this->endTime) {
            return null;
        }

        return $this->endTime - $this->startTime;
    }

    /**
     * Close process
     *
     * @param int $botId
     * @param string $symbol
     * @param bool $force
     * @return void
     */
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

    /**
     * Close all process
     *
     * @param bool $force
     * @return void
     */
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

    /**
     * Process bot
     *
     * @param int $botId
     * @param array $symbols
     * @return void
     */
    private function processBot(int $botId, array $symbols): void
    {
        foreach ($symbols as $symbol) {
            $this->status[$botId][$symbol] = self::STATUS_RUN;

            $this->loop->futureTick(function () use ($botId, $symbol) {
                $this->process[$botId][$symbol] = $this->retrySymbol($botId, $symbol);
            });
        }
    }

    /**
     * Retry symbol
     *
     * @param int $botId
     * @param string $symbol
     * @return Process
     */
    private function retrySymbol(int $botId, string $symbol): Process
    {
        $log = new Log($botId);
        $process = new Process($this->buildCommand($botId, $symbol));

        $timer = $this->loop->addTimer($this->timeout, function () use ($process, $botId, $symbol, $log) {
            if (!$process->isRunning()) {
                $message = "STOP FINISHED-{$this->customerId}-{$botId}-{$symbol}";

                $log->register(LogLevel::LEVEL_INFO, $message);

                echo "$message\n";

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

            $message = "TIMEOUT-{$this->customerId}-{$botId}-{$symbol}";

            $log->register(LogLevel::LEVEL_WARNING, $message);

            echo "$message\n";
        });

        $this->retrys[$botId][$symbol] = $this->retrys[$botId][$symbol] ?? 0;

        $this->loop->futureTick(function () use ($process, $botId, $symbol, &$timer, $log) {
            $process->start($this->loop);

            $process->on('exit', function (?int $exitCode = null, ?int $termSignal = null) use ($process, $botId, $symbol, &$timer, $log) {
                $exitCode = $exitCode ?? $termSignal;

                switch (true) {
                    case $exitCode === Analyzer::RESULT_DEFAULT:
                    case $exitCode === Analyzer::RESULT_SUCCESS:
                        $message = "Bot-{$this->customerId}-{$botId}-{$symbol} - finished";

                        $log->register(LogLevel::LEVEL_INFO, $message);

                        echo "$message\n";

                        break;
                    case $exitCode === Analyzer::RESULT_CLOSED:
                        $message = "Bot-{$this->customerId}-{$botId}-{$symbol} - closed";

                        $log->register(LogLevel::LEVEL_INFO, $message);

                        echo "$message\n";
                        break;
                    case $exitCode === Analyzer::RESULT_CLOSED_TIMEOUT:
                        $message = "Bot-{$this->customerId}-{$botId}-{$symbol} - finished timeout";

                        $log->register(LogLevel::LEVEL_WARNING, $message);

                        echo "$message\n";
                        break;
                    default:
                        if ($exitCode !== Analyzer::RESULT_RESTART) {
                            $this->retrys[$botId][$symbol]++;
                        }

                        if ($this->retrys[$botId][$symbol] >= self::MAX_RETRY) {
                            $message = "Maximum attempts bot-{$this->customerId}-{$botId}-{$symbol}";

                            $log->register(LogLevel::LEVEL_WARNING, $message);

                            echo "$message\n";
                        } else {
                            $mgs = 'Starting bot';

                            if ($exitCode !== Analyzer::RESULT_RESTART) {
                                $mgs = 'Error processing bot';
                            }

                            $message = "{$mgs}-{$this->customerId}-{$botId}-{$symbol} - {$exitCode}:{$termSignal}";

                            $log->register(LogLevel::LEVEL_INFO, $message);

                            echo "$message\n";

                            $this->loop->futureTick(function () use ($botId, $symbol) {
                                $this->process[$botId][$symbol] = $this->retrySymbol($botId, $symbol);
                            });
                        }
                }

                $this->loop->cancelTimer($timer);

                $process->close();
            });

            $process->stdout->on('data', function ($output) use ($botId, $symbol, $log) {
                $outputTmp = explode("\n", $output);
                $outputTmp = implode("\n\t", $outputTmp);

                $message = "Bot-{$this->customerId}-{$botId}-{$symbol} - output:\n\t{$outputTmp}";

                $log->register(LogLevel::LEVEL_INFO, $message);

                echo "$message\n";
            });

            $process->stderr->on('data', function ($output) use ($botId, $symbol, $log) {
                $outputTmp = explode("\n", $output);
                $outputTmp = implode("\n\t", $outputTmp);

                $message = "Bot-{$this->customerId}-{$botId}-{$symbol} - output:\n\t{$outputTmp}";

                $log->register(LogLevel::LEVEL_ERROR, $message);

                echo "$message\n";
            });
        });

        return $process;
    }

    /**
     * Build command
     *
     * @param int $botId
     * @param string $symbol
     * @return array
     */
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
