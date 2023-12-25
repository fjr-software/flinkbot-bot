<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Account;

use FjrSoftware\Flinkbot\Bot\Model\Bots;
use FjrSoftware\Flinkbot\Bot\Model\Positions;
use FjrSoftware\Flinkbot\Bot\Model\Symbols;
use FjrSoftware\Flinkbot\Exchange\ExchangeInterface;

class Position
{
    /**
     * Constructor
     *
     * @param ExchangeInterface $exchange
     * @param Bots $bots
     */
    public function __construct(
        private readonly ExchangeInterface $exchange,
        private readonly Bots $bots
    ) {
    }

    /**
     * Update positions
     *
     * @param string $symbol
     * @return void
     */
    public function execute(string $symbol): void
    {
        $symbols = $this->get($symbol);

        foreach ($symbols as $symbol) {
            $positions = $this->exchange->getPosition($symbol->pair);

            foreach ($positions as $position) {
                $type = $position['marginType'] === 'cross' ? 'CROSSED' : 'ISOLATED';
                $size = abs((float) $position['positionAmt']);
                $status = $size > 0 ? 'open' : 'closed';
                $roiPercent = 0;

                if ($size > 0) {
                    $value1 = (float) $position['entryPrice'];
                    $value2 = (float) $position['markPrice'];

                    if ($position['positionSide'] === 'LONG') {
                        $value1 = (float) $position['markPrice'];
                        $value2 = (float) $position['entryPrice'];
                    }

                    $roiPercent = $this->exchange->percentage($value1, $value2) * $position['leverage'];
                }

                Positions::updateOrCreate(
                    [
                        'user_id' => $this->bots->user_id,
                        'symbol_id' => $symbol->id,
                        'side' => $position['positionSide']
                    ],
                    [
                        'entry_price' => (float) $position['entryPrice'],
                        'size' => $size,
                        'pnl_roi_percent' => $roiPercent,
                        'pnl_roi_value' => (float) $position['unRealizedProfit'],
                        'mark_price' => (float) $position['markPrice'],
                        'liquid_price' => (float) $position['liquidationPrice'],
                        'margin_type' => $type,
                        'status' => $status,
                    ]
                );
            }
        }
    }

    /**
     * Get positions
     *
     * @param string $symbol
     * @return array
     */
    public function get(string $symbol): array
    {
        return array_filter(
            Positions::where(['user_id' => 1])->get(),
            fn($position) => $position->symbol->pair === $symbol
        );
    }
}
