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
     */
    public function __construct(
        private readonly int $botId,
        private readonly string $symbol
    ) {
        $this->analyzer = new Analyzer($this->botId);
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
