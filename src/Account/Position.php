<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Account;

use FjrSoftware\Flinkbot\Bot\Model\Positions;

class Position
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
     * Update positions
     *
     * @param string $symbol
     * @return void
     */
    public function execute(string $symbol): void
    {
        $symbols = $this->get($symbol);

        foreach ($symbols as $symbol) {
            $account = $this->bot->getExchange()->getAccountInformation();
            $positions = $this->bot->getExchange()->getPosition($symbol->symbol->pair);
            $marginAccountPercent = 100 - $this->bot->getExchange()->percentage((float) $account['totalMarginBalance'], (float) $account['totalMaintMargin']);

            foreach ($positions as $position) {
                $marginSymbol = abs((float) $position['notional']) / $position['leverage'];
                $marginSymbolPercent = 100 - $this->bot->getExchange()->percentage((float) $account['totalWalletBalance'], (float) $marginSymbol);

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

                    $roiPercent = $this->bot->getExchange()->percentage($value1, $value2) * $position['leverage'];
                }

                Positions::updateOrCreate(
                    [
                        'user_id' => $this->bot->getUserId(),
                        'symbol_id' => $symbol->symbol->id,
                        'side' => $position['positionSide']
                    ],
                    [
                        'entry_price' => (float) $position['entryPrice'],
                        'size' => $size,
                        'pnl_roi_percent' => $roiPercent,
                        'pnl_roi_value' => (float) $position['unRealizedProfit'],
                        'margin_account_percent' => $marginAccountPercent,
                        'margin_symbol_percent' => $marginSymbolPercent,
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
        $positions = Positions::where(['user_id' => 1])->get();
        $result = [];

        foreach ($positions as $position) {
            if ($position->symbol->pair === $symbol) {
                $result[] = $position;
            }
        }

        return $result;
    }
}
