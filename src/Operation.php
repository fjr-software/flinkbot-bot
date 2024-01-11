<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

class Operation
{
    private Analyzer $analyzer;

    /**
     * Constructor
     *
     * @param int $botId
     * @param string $symbol
     * @param string $host
     */
    public function __construct(
        private readonly int $botId,
        private readonly string $symbol,
        private readonly string $host
    ) {
        $this->analyzer = new Analyzer($this->botId, $this->host);
    }

    /**
     * Run
     *
     * @return void
     */
    public function run(): void
    {
        $this->analyzer->run($this->symbol);
    }
}
