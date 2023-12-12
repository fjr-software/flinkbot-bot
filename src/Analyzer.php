<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Stream\ReadableResourceStream;

class Analyzer
{
    /**
     * @const int
     * Default result status.
     */
    public const RESULT_DEFAULT = 0;

    /**
     * @const int
     * Indicates successful operation.
     */
    public const RESULT_SUCCESS = 1;

    /**
     * @const int
     * Indicates a need for restart.
     */
    public const RESULT_RESTART = 2;

    /**
     * @const int
     * Indicates operation was closed.
     */
    public const RESULT_CLOSED = 3;

    /**
     * @const int
     * Indicates a timeout occurred.
     */
    public const RESULT_TIMEOUT = 4;

    /**
     * @const int
     * Indicates a closed operation due to timeout.
     */
    public const RESULT_CLOSED_TIMEOUT = 5;

    /**
     * @var int
     */
    private int $exitCode = self::RESULT_RESTART;

    /**
     * @var LoopInterfacee|null
     */
    private ?LoopInterface $loop = null;

    /**
     * @var ReadableResourceStream|null
     */
    private ?ReadableResourceStream $stream = null;

    /**
     * Constructor
     *
     * @param int $botId
     */
    public function __construct(
        private readonly int $botId
    ) {
        $this->loop = Loop::get();
        $this->start();
    }

    private function start(): void
    {
        echo "Started - " . date('Y-m-d H:i:s') . "\n";

        $this->stream = new ReadableResourceStream(STDIN);
        $this->stream->on('data', function (mixed $chunk) {
            if ($chunk === '@STOP') {
                $this->exitCode = self::RESULT_SUCCESS;
            }
        });
    }

    public function run(string $symbol): void
    {
        $this->loop->addPeriodicTimer(1, function ($timer) use (&$i, $symbol) {
            echo "Pending - {$symbol} " . date('Y-m-d H:i:s') . "\n";

            if (++$i >= 30) {
                $this->loop->cancelTimer($timer);
                $this->exit();
            }
        });

        $this->loop->run();
    }

    private function exit(): never
    {
        exit($this->exitCode);
    }
}
